<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures shared by the Super Admin, Admin and Principal
 * dashboards (each native-PHP dashboard had its own copy of this code).
 * Every status comes from getTeacherDayStatus(); nothing is re-implemented.
 */
class DashboardStats
{
    private array $events;

    private array $teachers;

    public function __construct()
    {
        $this->events = AttendanceData::activeEvents();
        $this->teachers = DB::table('teachers')
            ->select('id', 'fullname', 'id_number', 'department', 'created_at')
            ->where('is_deleted', 0)
            ->orderBy('fullname')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function events(): array
    {
        return $this->events;
    }

    public function teachers(): array
    {
        return $this->teachers;
    }

    public function totalPersonnel(): int
    {
        return count($this->teachers);
    }

    /** Regular / Part-Time / Contractual counts of active personnel. */
    public function employmentTypeCounts(): array
    {
        $counts = ['Regular' => 0, 'Part-Time' => 0, 'Contractual' => 0];

        DB::table('teachers')
            ->where('is_deleted', 0)
            ->groupBy('employment_type')
            ->selectRaw('employment_type, COUNT(*) AS total')
            ->get()
            ->each(function ($row) use (&$counts) {
                if (isset($counts[$row->employment_type])) {
                    $counts[$row->employment_type] = (int) $row->total;
                }
            });

        return $counts;
    }

    /** Teaching / Non-Teaching (academic_status) counts of active personnel. */
    public function academicStatusCounts(): array
    {
        return DB::table('teachers')
            ->where('is_deleted', 0)
            ->groupBy('academic_status')
            ->selectRaw('academic_status, COUNT(*) AS total')
            ->pluck('total', 'academic_status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Today's status counts and the personnel on leave today.
     *
     * @return array{counts: array<string, int>, on_leave: array<int, array{fullname: string, detail: string}>}
     */
    public function today(string $today): array
    {
        $counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'On Leave' => 0, 'Not Yet Recorded' => 0];
        $onLeave = [];

        foreach ($this->teachers as $teacher) {
            [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $today, $today);

            $result = getTeacherDayStatus(
                $today, $leaves, $adjustments, $this->events, $attendance[$today] ?? null, $absences, $schedules,
                teacherId: (int) $teacher['id']
            );

            $status = $result['status'];

            if ($status === 'School Event' || $status === 'Adjusted') {
                continue;
            }

            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            if ($status === 'On Leave') {
                $onLeave[] = ['fullname' => $teacher['fullname'], 'detail' => $result['detail']];
            }
        }

        return ['counts' => $counts, 'on_leave' => $onLeave];
    }

    /**
     * Mon–Sat trend of the current week (dates after today are left at 0).
     * Dates before a person's record was created are not counted for them.
     *
     * @return array{labels: string[], data: array<string, int[]>, start: string, end: string}
     */
    public function week(string $today): array
    {
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $weekEnd = date('Y-m-d', strtotime('saturday this week', strtotime($today)));

        $dates = [];
        $labels = [];
        for ($c = strtotime($weekStart); $c <= strtotime($weekEnd); $c = strtotime('+1 day', $c)) {
            $dates[] = date('Y-m-d', $c);
            $labels[] = date('D', $c);
        }

        $data = array_fill_keys(['Present', 'Late', 'Pending Review', 'Absent', 'On Leave'], array_fill(0, count($dates), 0));

        foreach ($this->teachers as $teacher) {
            [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $weekStart, $weekEnd);

            foreach ($dates as $i => $date) {
                if ($date > $today) {
                    continue;
                }

                $result = MonthlySummary::dayStatus($teacher, $date, $leaves, $adjustments, $this->events, $attendance[$date] ?? null, $absences, $schedules);

                if ($result === null || $result['status'] === 'School Event' || $result['status'] === 'Adjusted') {
                    continue;
                }

                if (isset($data[$result['status']])) {
                    $data[$result['status']][$i]++;
                }
            }
        }

        return ['labels' => $labels, 'data' => $data, 'start' => $weekStart, 'end' => $weekEnd];
    }

    /** Personnel with 3 or more confirmed absences so far this month (Principal). */
    public function flaggedAbsences(string $today, int $threshold = 3): array
    {
        $monthStart = date('Y-m-01', strtotime($today));
        $monthEnd = date('Y-m-t', strtotime($today));
        $end = $monthEnd > $today ? $today : $monthEnd;

        $flagged = [];

        foreach ($this->teachers as $teacher) {
            [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $monthStart, $end);

            $absentCount = 0;

            for ($c = strtotime($monthStart); $c <= strtotime($end); $c = strtotime('+1 day', $c)) {
                $date = date('Y-m-d', $c);
                $dayOfWeek = date('N', $c);

                // Sunday is always off; Saturday only for people scheduled on it.
                if ($dayOfWeek == 7 || ($dayOfWeek == 6 && ! isset($schedules[strtolower(date('l', $c))]))) {
                    continue;
                }

                $result = getTeacherDayStatus($date, $leaves, $adjustments, $this->events, $attendance[$date] ?? null, $absences, $schedules, teacherId: (int) $teacher['id']);

                if ($result['status'] === 'Absent') {
                    $absentCount++;
                }
            }

            if ($absentCount >= $threshold) {
                $flagged[] = ['fullname' => $teacher['fullname'], 'department' => $teacher['department'], 'absent_count' => $absentCount];
            }
        }

        usort($flagged, fn ($a, $b) => $b['absent_count'] <=> $a['absent_count']);

        return $flagged;
    }

    /** The first active School Schedule Change covering today, if any. */
    public function todaysEvent(string $today): ?array
    {
        foreach ($this->events as $event) {
            if ($today >= $event['date_from'] && $today <= $event['date_to']) {
                return $event;
            }
        }

        return null;
    }

    /** Today's scans, one row per person (the "Live Personnel Logs" card). */
    public static function liveLogs(string $today): array
    {
        return DB::table('attendance as a')
            ->join('teachers as t', 't.id', '=', 'a.teacher_id')
            ->where('a.date', $today)
            ->orderByRaw("GREATEST(COALESCE(a.am_arrival, '00:00:00'), COALESCE(a.am_departure, '00:00:00'),
                COALESCE(a.pm_arrival, '00:00:00'), COALESCE(a.pm_departure, '00:00:00')) DESC")
            ->get(['t.fullname', 't.id_number', 'a.am_arrival', 'a.am_departure', 'a.pm_arrival', 'a.pm_departure'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
