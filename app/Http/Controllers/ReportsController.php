<?php

namespace App\Http\Controllers;

use App\Domain\Attendance\MonthlySummary;
use App\Domain\Attendance\ReportsAnalytics;
use App\Domain\Attendance\ScanPhotoStrip;
use App\Models\Teacher;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Reports: Daily, Weekly and Monthly tabs, for All Personnel or one person.
 * One page for all three roles (the native-PHP system had a copy per role).
 *
 * The scan photo strip is built for the Super Admin only. Admin and
 * Principal pages never receive the photos or their URLs (RA 10173), so
 * hiding it is not left to CSS.
 */
abstract class ReportsController extends Controller
{
    private const TABS = ['daily', 'weekly', 'monthly'];

    abstract protected function role(): string;

    public function show(Request $request): View
    {
        $today = date('Y-m-d');
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'daily';
        $showPhotos = $this->role() === 'superadmin';

        $date = $this->selectedDate($request->query('date'), $today);
        [$weekStart, $weekEnd] = ReportsAnalytics::weekRange($this->selectedWeek($request->query('week'), $today));
        [$year, $month] = $this->selectedMonth($request);
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $person = Teacher::query()->active()
            ->find((int) $request->query('teacher_id'), ['id', 'id_number', 'fullname', 'department', 'academic_status', 'photo']);

        $reports = new ReportsAnalytics($today);
        $withPhotos = fn (array $rows) => $showPhotos ? ScanPhotoStrip::attach($rows) : $rows;

        if ($person) {
            $daily = $reports->personDays($person->id, $date, $date, false);
            $personWeek = $reports->personDays($person->id, $weekStart, $weekEnd, false);
            $personMonth = $reports->personDays($person->id, $monthStart, min($monthEnd, $today), true);
            $personMonthSummary = collect(MonthlySummary::build($monthStart, $monthEnd))->firstWhere('id', $person->id);
        } else {
            $daily = $reports->daily($date);
            $weekly = $reports->weekly($weekStart);
            $monthly = $reports->monthly($monthStart, $monthEnd);
        }

        $calendar = $reports->monthCalendar($monthStart, $monthEnd, $person?->id);

        // Daily lists only people with a status to report: "Not Yet Recorded"
        // (no scan yet today) is shown as a count instead. Past days never
        // have it; those people are Absent or Pending Review as usual.
        $dailyNotYetRecorded = count(array_filter($daily, fn (array $row) => $row['status'] === 'Not Yet Recorded'));
        $daily = array_values(array_filter($daily, fn (array $row) => $row['status'] !== 'Not Yet Recorded'));

        // Previous / next school week; never a week that has not started.
        $previousWeek = date('Y-m-d', strtotime($weekStart.' -7 days'));
        $nextWeek = date('Y-m-d', strtotime($weekStart.' +7 days'));

        // Print Absences: each tab's own range, with the Personnel filter.
        $absenceUrl = fn (string $period, string $from, string $to) => route('reports.absences', array_filter([
            'period' => $period, 'from' => $from, 'to' => $to, 'teacher_id' => $person?->id,
        ]));

        return view('reports', [
            'dailyNotYetRecorded' => $dailyNotYetRecorded,
            'previousWeek' => $previousWeek,
            'nextWeek' => $nextWeek <= $today ? $nextWeek : null,
            'absenceUrls' => [
                'daily' => $absenceUrl('daily', $date, $date),
                'weekly' => $absenceUrl('weekly', $weekStart, $weekEnd),
                'monthly' => $absenceUrl('monthly', $monthStart, $monthEnd),
            ],
            'role' => $this->role(),
            'activeTab' => $tab,
            'showPhotos' => $showPhotos,
            'personnelOptions' => Teacher::query()->active()->orderBy('fullname')->get(['id', 'fullname', 'id_number']),
            'person' => $person,
            'date' => $date,
            'today' => $today,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'month' => $month,
            'year' => $year,
            'monthStart' => $monthStart,
            'daily' => $withPhotos($daily),
            'weekly' => $weekly ?? null,
            'monthly' => $monthly ?? null,
            'calendar' => $calendar,
            'personWeek' => $withPhotos($personWeek ?? []),
            'personMonth' => $withPhotos($personMonth ?? []),
            'personMonthSummary' => $personMonthSummary ?? null,
        ]);
    }

    /** ?date=YYYY-MM-DD up to today, or today. */
    private function selectedDate(mixed $value, string $today): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return min($value, $today);
        }

        return $today;
    }

    /** A day in the week chosen by ?week=YYYY-Www (or a date in it); never a future week. */
    private function selectedWeek(mixed $value, string $today): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-W(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 53) {
            $monday = (new DateTimeImmutable)->setISODate((int) $m[1], (int) $m[2])->format('Y-m-d');

            return min($monday, $today);
        }

        return $this->selectedDate($value, $today);
    }

    /**
     * ?monthPicker=YYYY-MM, or ?month=&year=, or the current month.
     *
     * @return array{0: int, 1: int}
     */
    private function selectedMonth(Request $request): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('monthPicker'), $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } else {
            $year = (int) $request->query('year', date('Y'));
            $month = (int) $request->query('month', date('m'));
        }

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return [(int) date('Y'), (int) date('m')];
        }

        return [$year, $month];
    }
}
