<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Date-aware report rules and the Monthly Summary, ported from
 * dtr/report-core.php. One calculation shared by every role's
 * "Generate Summary Report" and the Principal's monthly report.
 *
 * A report only counts a person for dates on or after their record was
 * created (teachers.created_at), so adding someone later never changes the
 * totals of periods that are already over.
 */
class MonthlySummary
{
    /** Day status for a report, or null before the person existed. */
    public static function dayStatus(array $teacher, string $date, array $leaves, array $adjustments, array $allEvents, ?array $attendanceRow, array $confirmedAbsences, array $schedules): ?array
    {
        $eligibleFrom = PendingReview::eligibleFrom($teacher);

        if ($eligibleFrom !== null && $date < $eligibleFrom) {
            return null;
        }

        return getTeacherDayStatus(
            $date, $leaves, $adjustments, $allEvents, $attendanceRow, $confirmedAbsences, $schedules,
            teacherId: (int) $teacher['id']
        );
    }

    /** True when the person was created AFTER the given period ended. */
    public static function createdAfter(array $teacher, string $periodEndDate): bool
    {
        $eligibleFrom = PendingReview::eligibleFrom($teacher);

        return $eligibleFrom !== null && $eligibleFrom > $periodEndDate;
    }

    public static function formatHoursPretty(float $decimalHours): string
    {
        $hours = floor($decimalHours);
        $minutes = round(($decimalHours - $hours) * 60);

        if ($minutes == 60) {
            $hours++;
            $minutes = 0;
        }

        return $hours.' hr'.($hours != 1 ? 's ' : ' ').
            $minutes.' min'.($minutes != 1 ? 's' : '');
    }

    /**
     * Rows for every active person who is part of [$startDate, $endDate]
     * (first and last day of one month). Status counts stop at today; hours
     * cover the whole month, exactly as the DTR total does. With $teacherId,
     * only that person's row is built. Every person's data is read in a
     * fixed number of queries (AttendanceData::preloadMany()).
     */
    public static function build(string $startDate, string $endDate, ?int $teacherId = null): array
    {
        $today = date('Y-m-d');
        $effectiveEndDate = ($endDate > $today) ? $today : $endDate;

        $allEvents = AttendanceData::activeEvents();

        $settings = DB::table('system_settings')->where('id', 1)->first(['lunch_out', 'lunch_in']);
        $lunchOut = $settings->lunch_out ?? null;
        $lunchIn = $settings->lunch_in ?? null;

        $breakConfig = BreakConfig::load();
        $schedulesRule = WorkingDays::load();

        $summaryReports = [];

        $teachers = DB::table('teachers')
            ->select('id', 'id_number', 'fullname', 'department', 'academic_status', 'employment_type', 'created_at')
            ->where('is_deleted', 0)
            ->when($teacherId !== null, fn ($query) => $query->where('id', $teacherId))
            ->orderBy('fullname')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->reject(fn (array $teacher) => self::createdAfter($teacher, $endDate));

        $teacherIds = $teachers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $preloaded = AttendanceData::preloadMany($teacherIds, $startDate, $endDate);
        $remarks = AttendanceData::remarksByDateMany($teacherIds, $startDate, $endDate);

        foreach ($teachers as $teacher) {

            $teacherId = (int) $teacher['id'];

            [$leaves, $adjustments, $attendance, $confirmedAbsences, $schedules] = $preloaded[$teacherId];

            $remarksByDate = $remarks[$teacherId] ?? [];

            $onTime = $late = $absent = $onLeave = $workingDays = 0;

            $cursor = strtotime($startDate);
            $endCursor = strtotime($effectiveEndDate);

            while ($cursor <= $endCursor) {

                $date = date('Y-m-d', $cursor);

                // The person's own schedule decides (WorkingDays): a day they
                // are not scheduled on is "Not Scheduled" below and never
                // counted. Sundays are left out unless anyone works Sundays,
                // exactly as on the Monthly Report calendar.
                if (! $schedulesRule->isShown($date)) {
                    $cursor = strtotime('+1 day', $cursor);

                    continue;
                }

                $result = self::dayStatus(
                    $teacher, $date, $leaves, $adjustments, $allEvents, $attendance[$date] ?? null, $confirmedAbsences, $schedules
                );

                if ($result === null) {
                    $cursor = strtotime('+1 day', $cursor);

                    continue;
                }

                $status = $result['status'];

                if ($status === 'School Event' || $status === 'Adjusted' || $status === 'Not Scheduled') {
                    $cursor = strtotime('+1 day', $cursor);

                    continue;
                }

                $workingDays++;

                if ($status === 'Present') {
                    $onTime++;
                } elseif ($status === 'Late') {
                    $late++;
                } elseif ($status === 'Absent') {
                    $absent++;
                } elseif ($status === 'On Leave') {
                    $onLeave++;
                }
                // 'Pending Review' / 'Not Yet Recorded' count as working days only.

                $cursor = strtotime('+1 day', $cursor);
            }

            // Total hours: the same per-day rules as the individual DTR,
            // over the whole month.
            $totalHours = 0.0;

            $hoursCursor = strtotime($startDate);
            $hoursEnd = strtotime($endDate);

            while ($hoursCursor <= $hoursEnd) {

                $hoursDate = date('Y-m-d', $hoursCursor);

                $totalHours += getTeacherDayHours(
                    $hoursDate, $leaves, $adjustments, $allEvents,
                    $remarksByDate[$hoursDate] ?? null,
                    $attendance[$hoursDate] ?? null,
                    $confirmedAbsences, $schedules, $lunchOut, $lunchIn,
                    teacherId: $teacherId,
                    breakConfig: $breakConfig
                );

                $hoursCursor = strtotime('+1 day', $hoursCursor);
            }

            $totalMinutes = $totalHours * 60;

            $teacher['on_time'] = $onTime;
            $teacher['late_count'] = $late;
            $teacher['absent_count'] = $absent;
            $teacher['on_leave_count'] = $onLeave;
            $teacher['total'] = $workingDays;
            $teacher['total_hours_pretty'] = self::formatHoursPretty($totalMinutes / 60);
            $teacher['total_minutes_only'] = number_format($totalMinutes).' minutes';

            $summaryReports[] = $teacher;
        }

        return $summaryReports;
    }
}
