<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Pending Review for the Super Admin attendance-review workflow, ported
 * from dtr/review-core.php.
 *
 * Pending Review is COMPUTED from the attendance / schedule data
 * (getTeacherDayStatus()); there is no pending_review table, so an item can
 * never be duplicated and it resolves itself as soon as the day is
 * confirmed absent or adjusted. Review periods start on the first Sunday of
 * each month (dtrReviewPeriodStart()).
 */
class PendingReview
{
    /** The Pending Review reason of a scan recorded on a leave day. */
    public const LEAVE_CONFLICT = 'Scan on Leave Day';

    /**
     * System-managed lower boundary of the queue: set once, to the start of
     * the review period in progress the first time this runs, so days from
     * before the review workflow existed are never dumped into the queue.
     */
    public static function ensureStartDate(?string $today = null): string
    {
        $today = $today ?: date('Y-m-d');

        $existing = DB::table('system_settings')->where('id', 1)->value('review_start_date');

        if (! empty($existing)) {
            return $existing;
        }

        $initial = dtrReviewPeriodStart($today);

        DB::table('system_settings')->where('id', 1)->whereNull('review_start_date')
            ->update(['review_start_date' => $initial]);

        return $initial;
    }

    /**
     * The date a person became eligible for Pending Review: the date part of
     * teachers.created_at. NULL/unparseable -> no restriction.
     */
    public static function eligibleFrom(array $teacher): ?string
    {
        $created = isset($teacher['created_at']) ? substr((string) $teacher['created_at'], 0, 10) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $created) ? $created : null;
    }

    /**
     * Unresolved Pending Review items for ONE person between two dates.
     * $preloaded: that person's AttendanceData::preload() data covering the
     * range (compute() reads everyone's at once); read here when omitted.
     */
    public static function forTeacher(array $teacher, string $startDate, string $endDate, array $allEvents, ?array $preloaded = null): array
    {
        $items = [];

        $eligibleFrom = self::eligibleFrom($teacher);

        if ($eligibleFrom !== null && $eligibleFrom > $startDate) {
            $startDate = $eligibleFrom;
        }

        if ($startDate > $endDate) {
            return $items;
        }

        [$leaves, $adjustments, $attendance, $confirmedAbsences, $schedules] =
            $preloaded ?? AttendanceData::preload((int) $teacher['id'], $startDate, $endDate);

        $cursor = strtotime($startDate);
        $end = strtotime($endDate);

        while ($cursor <= $end) {

            $date = date('Y-m-d', $cursor);
            $dayName = strtolower(date('l', $cursor));

            // Only days this person was actually scheduled to work.
            if (! isset($schedules[$dayName])) {
                $cursor = strtotime('+1 day', $cursor);

                continue;
            }

            $result = getTeacherDayStatus(
                $date, $leaves, $adjustments, $allEvents, $attendance[$date] ?? null,
                $confirmedAbsences, $schedules, teacherId: (int) $teacher['id']
            );

            // Rules effective 2026-09-27: a day that HAS real scans can still
            // need review — Missing OUT, Early Departure or Overtime. Any
            // active adjustment for the day resolves it.
            if (
                dtrRulesApply($date)
                && in_array($result['status'], ['Present', 'Late'], true)
                && ! isset($adjustments[$date])
                && ! empty($attendance[$date])
            ) {
                $halfEvent = null;

                foreach ($allEvents as $event) {
                    if (
                        ($event['status'] ?? '') === 'Active' && ($event['duration'] ?? '') === 'Half Day'
                        && ! empty($event['start_time'])
                        && $date >= $event['date_from'] && $date <= $event['date_to']
                        && ! dtrEventExemptsTeacher($event, $teacher['id'], $schedules[$dayName])
                    ) {
                        $halfEvent = $event;
                        break;
                    }
                }

                $calc = dtrDayCalc($schedules[$dayName], $attendance[$date], null, [
                    'half_session' => $halfEvent ? (($halfEvent['start_time'] < '12:00:00') ? 'AM' : 'PM') : null,
                    'half_start' => $halfEvent['start_time'] ?? '',
                    'half_end' => $halfEvent['end_time'] ?? '',
                    'cutoff_pm' => '13:00:00',
                ]);

                // An overnight shift is only "missing" once its (next-day) end has passed.
                $shiftEndDate = (dtrScheduleShape($schedules[$dayName]) === 'overnight')
                    ? date('Y-m-d', strtotime($date.' +1 day'))
                    : $date;

                $flag = null;

                if ($calc['missing_out'] && $shiftEndDate <= $endDate) {
                    $flag = 'Missing OUT';
                } elseif ($calc['overtime'] && ! $calc['overtime_approved']) {
                    $flag = 'Overtime';
                } elseif ($calc['early_departure']) {
                    $flag = 'Early Departure';
                }

                if ($flag !== null) {
                    $items[] = [
                        'teacher_id' => (int) $teacher['id'],
                        'fullname' => $teacher['fullname'],
                        'id_number' => $teacher['id_number'],
                        'department' => $teacher['department'],
                        'date' => $date,
                        'scheduled_in' => $schedules[$dayName]['time_in'],
                        'scheduled_out' => $schedules[$dayName]['time_out'],
                        'shape' => dtrScheduleShape($schedules[$dayName]),
                        'scheduled_to_work_event' => null,
                        'reason' => $flag,
                        'issue' => $flag,
                        'real_in' => $calc['in'],
                        'real_out' => $calc['out'],
                    ];
                }
            }

            if ($result['status'] === 'Pending Review') {

                // Was this person explicitly scheduled to work through a
                // School Schedule Change that covers this date?
                $scheduledEvent = null;

                foreach ($allEvents as $event) {
                    if (
                        $date >= $event['date_from'] && $date <= $event['date_to']
                        && dtrEventExemptsTeacher($event, $teacher['id'], $schedules[$dayName])
                    ) {
                        $scheduledEvent = $event['event_name'];
                        break;
                    }
                }

                $items[] = [
                    'teacher_id' => (int) $teacher['id'],
                    'fullname' => $teacher['fullname'],
                    'id_number' => $teacher['id_number'],
                    'department' => $teacher['department'],
                    'date' => $date,
                    'scheduled_in' => $schedules[$dayName]['time_in'],
                    'scheduled_out' => $schedules[$dayName]['time_out'],
                    'shape' => dtrScheduleShape($schedules[$dayName]),
                    'scheduled_to_work_event' => $scheduledEvent,
                    'reason' => $scheduledEvent !== null
                        ? 'Possible Missing Attendance — No Scan'
                        : null,
                ];
            }

            $cursor = strtotime('+1 day', $cursor);
        }

        return $items;
    }

    /**
     * All unresolved items from the boundary up to yesterday, plus every
     * unreviewed scan on a leave day (leaveConflicts(), up to today).
     * Options: teacher_id (one person), today / start / end (overrides, for tests).
     */
    public static function compute(array $opts = []): array
    {
        $today = $opts['today'] ?? date('Y-m-d');
        $startDate = $opts['start'] ?? self::ensureStartDate($today);
        $endDate = $opts['end'] ?? date('Y-m-d', strtotime($today.' -1 day'));
        $teacherId = (int) ($opts['teacher_id'] ?? 0);

        $items = self::leaveConflicts($teacherId);

        if ($startDate <= $endDate) {
            $query = DB::table('teachers')->select('id', 'fullname', 'id_number', 'department', 'created_at');

            $teachers = ($teacherId > 0
                ? $query->where('id', $teacherId)
                : $query->where('is_deleted', 0)->orderBy('fullname'))
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $allEvents = AttendanceData::activeEvents();
            $preloaded = AttendanceData::preloadMany(array_column($teachers, 'id'), $startDate, $endDate);

            foreach ($teachers as $teacher) {
                $items = array_merge($items, self::forTeacher($teacher, $startDate, $endDate, $allEvents, $preloaded[(int) $teacher['id']]));
            }
        }

        usort($items, fn ($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['fullname'], $b['fullname']));

        return $items;
    }

    /**
     * Kiosk scans recorded on a leave day that the Super Admin has not
     * reviewed yet (attendance_leave_conflicts, written by the Scanner). The
     * scan and the leave both stand; the item only asks for a look. Marking
     * it reviewed, or adding an adjustment for the day, resolves it.
     *
     * @return list<array<string, mixed>>
     */
    public static function leaveConflicts(int $teacherId = 0): array
    {
        $rows = DB::table('attendance_leave_conflicts as c')
            ->join('teachers as t', 't.id', '=', 'c.teacher_id')
            ->leftJoin('attendance as a', fn ($join) => $join->on('a.teacher_id', '=', 'c.teacher_id')->on('a.date', '=', 'c.attendance_date'))
            ->whereNull('c.reviewed_at')
            ->where('t.is_deleted', 0)
            ->when($teacherId > 0, fn ($query) => $query->where('c.teacher_id', $teacherId))
            ->whereNotExists(fn ($query) => $query->from('attendance_adjustments as aa')
                ->whereColumn('aa.teacher_id', 'c.teacher_id')
                ->whereColumn('aa.adjustment_date', 'c.attendance_date')
                ->where('aa.is_deleted', 0))
            ->get(['c.id', 'c.teacher_id', 'c.attendance_date', 'c.leave_type', 't.fullname', 't.id_number', 't.department',
                'a.am_arrival', 'a.am_departure', 'a.pm_arrival', 'a.pm_departure']);

        if ($rows->isEmpty()) {
            return [];
        }

        $schedules = [];
        DB::table('teacher_schedules')
            ->whereIn('teacher_id', $rows->pluck('teacher_id')->unique()->all())
            ->get(['teacher_id', 'day', 'time_in', 'time_out'])
            ->each(function ($row) use (&$schedules) {
                $schedules[(int) $row->teacher_id][strtolower($row->day)] = ['time_in' => $row->time_in, 'time_out' => $row->time_out];
            });

        return $rows->map(function ($row) use ($schedules) {
            $schedule = $schedules[(int) $row->teacher_id][strtolower(date('l', strtotime($row->attendance_date)))] ?? null;
            $scans = ['am_arrival' => $row->am_arrival, 'am_departure' => $row->am_departure, 'pm_arrival' => $row->pm_arrival, 'pm_departure' => $row->pm_departure];
            [$in, $out] = dtrInOutOf(dtrEffectiveRecord($scans, null));

            return [
                'teacher_id' => (int) $row->teacher_id,
                'fullname' => $row->fullname,
                'id_number' => $row->id_number,
                'department' => $row->department,
                'date' => $row->attendance_date,
                'scheduled_in' => $schedule['time_in'] ?? null,
                'scheduled_out' => $schedule['time_out'] ?? null,
                'shape' => $schedule ? dtrScheduleShape($schedule) : null,
                'scheduled_to_work_event' => null,
                'reason' => self::LEAVE_CONFLICT,
                'issue' => self::LEAVE_CONFLICT,
                'real_in' => $in,
                'real_out' => $out,
                'leave_conflict_id' => (int) $row->id,
                'leave_type' => $row->leave_type,
            ];
        })->all();
    }

    /** Items grouped into the current review period and previous ones (newest first). */
    public static function groupByPeriod(array $items, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $currentStart = dtrReviewPeriodStart($today);

        $byPeriod = [];

        foreach ($items as $item) {
            $byPeriod[dtrReviewPeriodStart($item['date'])][] = $item;
        }

        $make = fn ($start, $periodItems) => [
            'period_start' => $start,
            'period_end' => dtrReviewPeriodEnd($start),
            'label' => dtrReviewPeriodLabel($start),
            'count' => count($periodItems),
            'items' => $periodItems,
        ];

        $current = $make($currentStart, $byPeriod[$currentStart] ?? []);

        $previous = [];

        krsort($byPeriod);

        foreach ($byPeriod as $start => $periodItems) {
            if ($start !== $currentStart) {
                $previous[] = $make($start, $periodItems);
            }
        }

        $previousCount = array_sum(array_column($previous, 'count'));

        return [
            'current' => $current,
            'previous' => $previous,
            'counts' => [
                'current' => $current['count'],
                'previous' => $previousCount,
                'total' => $current['count'] + $previousCount,
            ],
        ];
    }

    /** Counts + reminder state for the sidebar badge and dashboard reminder. */
    public static function summary(?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');

        $groups = self::groupByPeriod(self::compute(['today' => $today]), $today);

        $dismissed = DB::table('system_settings')->where('id', 1)->value('review_reminder_dismissed_period');

        $currentStart = $groups['current']['period_start'];

        return [
            'total' => $groups['counts']['total'],
            'current' => $groups['counts']['current'],
            'previous' => $groups['counts']['previous'],
            'current_period_start' => $currentStart,
            'current_period_label' => $groups['current']['label'],
            // A new review period has begun, older items are still
            // unresolved, and THIS period's reminder hasn't been dismissed.
            'reminder_due' => $groups['counts']['previous'] > 0 && $dismissed !== $currentStart,
        ];
    }
}
