<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Reports & Analytics (weekly roster/chart/lists and monthly totals/lists),
 * shared by the Super Admin, Admin and Principal pages — the native-PHP
 * system had three copies of this code. Dates before a person's record was
 * created are not counted for them.
 */
class ReportsAnalytics
{
    public const STATUSES = ['Present', 'Late', 'Pending Review', 'Absent', 'On Leave'];

    private array $events;

    private array $teachers;

    public function __construct(private string $today)
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

    /** Mon–Sat of the current week: chart data, name lists and the roster grid. */
    public function weekly(): array
    {
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($this->today)));
        $weekEnd = date('Y-m-d', strtotime('saturday this week', strtotime($this->today)));

        $dates = [];
        $labels = [];
        for ($c = strtotime($weekStart); $c <= strtotime($weekEnd); $c = strtotime('+1 day', $c)) {
            $dates[] = date('Y-m-d', $c);
            $labels[] = date('D', $c);
        }

        $chart = array_fill_keys(self::STATUSES, array_fill(0, count($dates), 0));
        $lists = array_fill_keys(self::STATUSES, []);
        $roster = [];

        foreach ($this->teachers as $teacher) {
            [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $weekStart, $weekEnd);

            $roster[$teacher['id']] = [
                'fullname' => $teacher['fullname'],
                'id_number' => $teacher['id_number'],
                'department' => $teacher['department'],
                'days' => [],
            ];

            foreach ($dates as $i => $date) {
                // Future days are not evaluated.
                if ($date > $this->today) {
                    $roster[$teacher['id']]['days'][$date] = '';
                    continue;
                }

                $result = MonthlySummary::dayStatus($teacher, $date, $leaves, $adjustments, $this->events, $attendance[$date] ?? null, $absences, $schedules);

                if ($result === null) {
                    $roster[$teacher['id']]['days'][$date] = '';
                    continue;
                }

                $status = $result['status'];
                $roster[$teacher['id']]['days'][$date] = $status;

                if ($status === 'School Event' || $status === 'Adjusted') {
                    continue;
                }

                if (isset($chart[$status])) {
                    $chart[$status][$i]++;
                }

                if (isset($lists[$status])) {
                    $lists[$status][] = $teacher['fullname'].' ('.date('D, M j', strtotime($date)).')';
                }
            }
        }

        return [
            'start' => $weekStart,
            'end' => $weekEnd,
            'dates' => $dates,
            'labels' => $labels,
            'chart' => $chart,
            'lists' => $lists,
            'roster' => $roster,
        ];
    }

    /** Status totals and per-person counts for one month (up to today). */
    public function monthly(string $monthStart, string $monthEnd): array
    {
        $end = $monthEnd > $this->today ? $this->today : $monthEnd;

        $totals = array_fill_keys(self::STATUSES, 0);
        $lists = array_fill_keys(self::STATUSES, []);

        foreach ($this->teachers as $teacher) {
            [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $monthStart, $end);

            $counts = array_fill_keys(self::STATUSES, 0);

            for ($c = strtotime($monthStart); $c <= strtotime($end); $c = strtotime('+1 day', $c)) {
                $date = date('Y-m-d', $c);
                $dayOfWeek = date('N', $c);

                // Sunday is always off; Saturday only for people scheduled on it.
                if ($dayOfWeek == 7 || ($dayOfWeek == 6 && ! isset($schedules[strtolower(date('l', $c))]))) {
                    continue;
                }

                $result = MonthlySummary::dayStatus($teacher, $date, $leaves, $adjustments, $this->events, $attendance[$date] ?? null, $absences, $schedules);

                if ($result === null) {
                    continue;
                }

                $status = $result['status'];

                if ($status !== 'School Event' && $status !== 'Adjusted' && isset($counts[$status])) {
                    $counts[$status]++;
                }
            }

            foreach ($counts as $status => $count) {
                if ($count > 0) {
                    $totals[$status] += $count;
                    $lists[$status][] = $teacher['fullname'].' ('.$count.')';
                }
            }
        }

        return ['totals' => $totals, 'lists' => $lists];
    }
}
