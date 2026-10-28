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

    private const SCAN_COLUMNS = ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'];

    private array $events;

    private array $teachers;

    /** @var array<string, list<array{date: string, status: string, detail: string, scans: ?array}>> */
    private array $monthDaysCache = [];

    public function __construct(private string $today)
    {
        $this->events = AttendanceData::activeEvents();
        $this->teachers = DB::table('teachers')
            ->select('id', 'fullname', 'id_number', 'department', 'academic_status', 'created_at')
            ->where('is_deleted', 0)
            ->orderBy('fullname')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Monday and Saturday of the week that contains $anyDay.
     *
     * @return array{0: string, 1: string}
     */
    public static function weekRange(string $anyDay): array
    {
        return [
            date('Y-m-d', strtotime('monday this week', strtotime($anyDay))),
            date('Y-m-d', strtotime('saturday this week', strtotime($anyDay))),
        ];
    }

    /** Mon–Sat of the week containing $anyDay (default: this week): chart data, name lists and the roster grid. */
    public function weekly(?string $anyDay = null): array
    {
        [$weekStart, $weekEnd] = self::weekRange($anyDay ?? $this->today);

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
        $totals = array_fill_keys(self::STATUSES, 0);
        $lists = array_fill_keys(self::STATUSES, []);

        foreach ($this->teachers as $teacher) {
            $counts = array_fill_keys(self::STATUSES, 0);

            foreach ($this->monthDays($teacher, $monthStart, $monthEnd) as $day) {
                if (isset($counts[$day['status']])) {
                    $counts[$day['status']]++;
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

    /**
     * The Monthly tab's calendar: Mon–Sat weeks, and for each day up to
     * today either the status counts and names (All Personnel) or one
     * person's status and scans. The days and statuses are exactly the
     * ones monthly() counts, so the calendar adds up to its lists. No
     * scan photos, for any role.
     *
     * @return array{weeks: list<list<?string>>, days: array<string, array{future: bool, label: ?string, labelTitle: ?string, counts?: array<string, int>, groups?: array<string, list<array{name: string, time: string, detail: string}>>, status?: ?string, detail?: string, scans?: array<string, string>}>}
     */
    public function monthCalendar(string $monthStart, string $monthEnd, ?int $teacherId = null): array
    {
        $days = [];

        for ($c = strtotime($monthStart); $c <= strtotime($monthEnd); $c = strtotime('+1 day', $c)) {
            $date = date('Y-m-d', $c);

            if (date('N', $c) == 7) {
                continue;
            }

            $event = $this->wholeDayEvent($date);

            $days[$date] = [
                'future' => $date > $this->today,
                'label' => $event ? ($event['event_type'] === 'Other' ? $event['event_name'] : $event['event_type']) : null,
                'labelTitle' => $event['event_name'] ?? null,
            ] + ($teacherId === null
                ? ['counts' => [], 'groups' => []]
                : ['status' => null, 'detail' => '', 'scans' => []]);
        }

        foreach ($this->teachers as $teacher) {
            if ($teacherId !== null && (int) $teacher['id'] !== $teacherId) {
                continue;
            }

            foreach ($this->monthDays($teacher, $monthStart, $monthEnd) as $day) {
                $date = $day['date'];
                $scans = $day['scans'] ?? [];

                if ($teacherId !== null) {
                    $days[$date]['status'] = $day['status'];
                    $days[$date]['detail'] = $day['detail'];
                    $days[$date]['scans'] = array_map(
                        fn (string $column) => self::clockTime($scans[$column] ?? null),
                        array_combine(self::SCAN_COLUMNS, self::SCAN_COLUMNS)
                    );

                    continue;
                }

                if (! in_array($day['status'], self::STATUSES, true)) {
                    continue;
                }

                $days[$date]['counts'][$day['status']] = ($days[$date]['counts'][$day['status']] ?? 0) + 1;
                $days[$date]['groups'][$day['status']][] = [
                    'name' => $teacher['fullname'],
                    'time' => self::clockTime($scans['am_arrival'] ?? $scans['pm_arrival'] ?? null),
                    'detail' => $day['status'] === 'On Leave' ? $day['detail'] : '',
                ];
            }
        }

        // A past Saturday nobody works on (or this person does not).
        foreach ($days as $date => $day) {
            $nobodyWorked = $teacherId === null ? $day['counts'] === [] : $day['status'] === null;

            if (! $day['future'] && $day['label'] === null && $nobodyWorked && date('N', strtotime($date)) == 6) {
                $days[$date]['label'] = 'No work';
            }
        }

        return ['weeks' => self::calendarWeeks($monthStart, $monthEnd), 'days' => $days];
    }

    /**
     * One person's days of [$monthStart, $monthEnd] up to today, as the
     * monthly report sees them: Sundays and unscheduled Saturdays are
     * skipped, so are days before the person's record existed. Every
     * status is returned; callers count only self::STATUSES. Remembered,
     * so the lists and the calendar of one page read the data once.
     *
     * @return list<array{date: string, status: string, detail: string, scans: ?array}>
     */
    private function monthDays(array $teacher, string $monthStart, string $monthEnd): array
    {
        $end = $monthEnd > $this->today ? $this->today : $monthEnd;
        $key = $teacher['id'].'|'.$monthStart.'|'.$end;

        if (isset($this->monthDaysCache[$key])) {
            return $this->monthDaysCache[$key];
        }

        [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $monthStart, $end);

        $days = [];

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

            $days[] = [
                'date' => $date,
                'status' => (string) $result['status'],
                'detail' => (string) ($result['detail'] ?? ''),
                'scans' => $attendance[$date] ?? null,
            ];
        }

        return $this->monthDaysCache[$key] = $days;
    }

    /** The active whole-day school event on $date, if any (Half Day events never blank a day). */
    private function wholeDayEvent(string $date): ?array
    {
        foreach ($this->events as $event) {
            if (($event['duration'] ?? '') !== 'Half Day' && $date >= $event['date_from'] && $date <= $event['date_to']) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Mon–Sat rows covering the month; days of other months are null.
     *
     * @return list<list<?string>>
     */
    private static function calendarWeeks(string $monthStart, string $monthEnd): array
    {
        $weeks = [];

        for ($monday = strtotime('monday this week', strtotime($monthStart)); $monday <= strtotime($monthEnd); $monday = strtotime('+7 days', $monday)) {
            $week = [];

            for ($i = 0; $i < 6; $i++) {
                $date = date('Y-m-d', strtotime("+{$i} days", $monday));
                $week[] = $date >= $monthStart && $date <= $monthEnd ? $date : null;
            }

            if (array_filter($week)) {
                $weeks[] = $week;
            }
        }

        return $weeks;
    }

    /** "07:28:00" -> "7:28 AM"; no time -> "". */
    private static function clockTime(?string $time): string
    {
        return $time ? date('g:i A', strtotime($time)) : '';
    }

    /**
     * One date's scans and status for every person whose record existed then.
     *
     * @return list<array{teacher_id: int, fullname: string, id_number: string, department: string, date: string, am_arrival: ?string, am_departure: ?string, pm_arrival: ?string, pm_departure: ?string, status: string, detail: string}>
     */
    public function daily(string $date): array
    {
        $rows = [];

        foreach ($this->teachers as $teacher) {
            array_push($rows, ...$this->dayRows($teacher, $date, $date, false));
        }

        return $rows;
    }

    /**
     * The Daily tab and its printout: one date's rows for everyone, or for
     * one person. Only people with a status to report are listed: "Not Yet
     * Recorded" (no scan yet today) is counted instead. Past days never have
     * it; those people are Absent or Pending Review as usual. Listed by
     * latest scan, newest first (newestScanFirst).
     *
     * @return array{rows: list<array<string, mixed>>, not_yet_recorded: int}
     */
    public function dailyReport(string $date, ?int $teacherId = null): array
    {
        $rows = $teacherId === null ? $this->daily($date) : $this->personDays($teacherId, $date, $date, false);
        $notYetRecorded = fn (array $row) => $row['status'] === 'Not Yet Recorded';

        return [
            'rows' => self::newestScanFirst(array_filter($rows, fn (array $row) => ! $notYetRecorded($row))),
            'not_yet_recorded' => count(array_filter($rows, $notYetRecorded)),
        ];
    }

    /**
     * The daily list's order: each person's latest kiosk scan of the day,
     * newest first, then everyone without a scan. The rows hold the
     * attendance table's real scans only (adjustments and the auto-filled
     * lunch are never stored there). usort is stable, so equal times and
     * the no-scan group keep the by-name order of $this->teachers.
     *
     * A departure earlier than the day's first arrival is the next
     * morning's OUT of an overnight shift (dtrLastScanTs); a day shift
     * never has one, since the kiosk records an arrival first.
     *
     * @param  array<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function newestScanFirst(array $rows): array
    {
        $keyed = array_map(fn (array $row) => [dtrLastScanTs($row, $row['date'], true) ?? PHP_INT_MIN, $row], array_values($rows));

        usort($keyed, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_column($keyed, 1);
    }

    /**
     * One person's working days of a month up to today (the Monthly tab and
     * its printout).
     *
     * @return list<array<string, mixed>>
     */
    public function personMonth(int $teacherId, string $monthStart, string $monthEnd): array
    {
        return $this->personDays($teacherId, $monthStart, min($monthEnd, $this->today), true);
    }

    /**
     * One person's scans and status for each day of [$from, $to]. With
     * $workingDaysOnly, Sundays and unscheduled Saturdays are left out
     * unless the person scanned that day (the monthly counts skip them too).
     *
     * @return list<array{teacher_id: int, fullname: string, id_number: string, department: string, date: string, am_arrival: ?string, am_departure: ?string, pm_arrival: ?string, pm_departure: ?string, status: string, detail: string}>
     */
    public function personDays(int $teacherId, string $from, string $to, bool $workingDaysOnly): array
    {
        foreach ($this->teachers as $teacher) {
            if ((int) $teacher['id'] === $teacherId) {
                return $this->dayRows($teacher, $from, $to, $workingDaysOnly);
            }
        }

        return [];
    }

    /**
     * Confirmed absences ("Absent" days) in [$from, $to], up to today, for
     * everyone or one person. Only working days count: Sundays and
     * unscheduled Saturdays are skipped, and Pending Review, On Leave,
     * school events and Not Yet Recorded are not absences.
     *
     * @return list<array{id: int, fullname: string, id_number: string, department: string, academic_status: string, dates: list<string>}>
     */
    public function absences(string $from, string $to, ?int $teacherId = null): array
    {
        $absences = [];

        foreach ($this->teachers as $teacher) {
            if ($teacherId !== null && (int) $teacher['id'] !== $teacherId) {
                continue;
            }

            $dates = array_column(array_filter(
                $this->dayRows($teacher, $from, min($to, $this->today), true),
                fn (array $row) => $row['status'] === 'Absent'
            ), 'date');

            if ($dates) {
                $absences[] = [
                    'id' => (int) $teacher['id'],
                    'fullname' => $teacher['fullname'],
                    'id_number' => $teacher['id_number'],
                    'department' => $teacher['department'],
                    'academic_status' => $teacher['academic_status'],
                    'dates' => array_values($dates),
                ];
            }
        }

        return $absences;
    }

    private function dayRows(array $teacher, string $from, string $to, bool $workingDaysOnly): array
    {
        if ($from > $to) {
            return [];
        }

        [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $from, $to);

        $rows = [];

        for ($c = strtotime($from); $c <= strtotime($to); $c = strtotime('+1 day', $c)) {
            $date = date('Y-m-d', $c);
            $scans = $attendance[$date] ?? null;
            $dayOfWeek = date('N', $c);

            if ($workingDaysOnly && $scans === null
                && ($dayOfWeek == 7 || ($dayOfWeek == 6 && ! isset($schedules[strtolower(date('l', $c))])))) {
                continue;
            }

            // Future days are not evaluated.
            $result = $date > $this->today
                ? ['status' => '', 'detail' => '']
                : MonthlySummary::dayStatus($teacher, $date, $leaves, $adjustments, $this->events, $scans, $absences, $schedules);

            // Before the person's record existed.
            if ($result === null) {
                continue;
            }

            $rows[] = [
                'teacher_id' => (int) $teacher['id'],
                'fullname' => $teacher['fullname'],
                'id_number' => $teacher['id_number'],
                'department' => $teacher['department'],
                'date' => $date,
                'am_arrival' => $scans['am_arrival'] ?? null,
                'am_departure' => $scans['am_departure'] ?? null,
                'pm_arrival' => $scans['pm_arrival'] ?? null,
                'pm_departure' => $scans['pm_departure'] ?? null,
                'status' => (string) $result['status'],
                'detail' => (string) ($result['detail'] ?? ''),
            ];
        }

        return $rows;
    }
}
