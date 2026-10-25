<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\MonthlySummary;
use App\Domain\Attendance\ReportsAnalytics;
use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Support\Printout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable Daily Attendance Report (All Personnel or one person) and
 * one person's Weekly Roster / Monthly Summary Report: the same section the
 * Reports tab shows, under the letterhead. Never with scan photos, for any
 * role (the Super Admin sees them on screen only). The Reports page fetches
 * this HTML and prints it.
 */
class AttendancePrintController extends Controller
{
    /** @var array<string, array{0: string, 1: string}> period => [report name, heading] */
    private const PERIODS = [
        'daily' => ['Daily Report', 'Daily Attendance Report'],
        'weekly' => ['Weekly Roster', 'Weekly Attendance Roster'],
        'monthly' => ['Monthly Summary Report', 'Monthly Attendance Summary Report'],
    ];

    public function __invoke(Request $request): Response
    {
        $today = date('Y-m-d');
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? $request->query('period') : 'daily';
        $date = $this->date($request->string('date')->toString());

        abort_unless($date && ($period !== 'daily' || $date <= $today), 422, 'Invalid date.');
        abort_if($period !== 'daily' && ! $request->filled('teacher_id'), 422, 'Choose a person.');

        $person = null;
        if ($request->filled('teacher_id')) {
            $person = Teacher::query()->active()->find((int) $request->query('teacher_id'), ['id', 'id_number', 'fullname', 'department', 'academic_status', 'photo']);
            abort_unless($person, 404);
        }

        $reports = new ReportsAnalytics($today);
        $section = ['section' => $period, 'person' => $person, 'notYetRecorded' => 0, 'monthSummary' => null];

        if ($period === 'daily') {
            ['rows' => $section['rows'], 'not_yet_recorded' => $section['notYetRecorded']] = $reports->dailyReport($date, $person?->id);
            $range = $date;
            $periodLabel = date('l, F j, Y', strtotime($date));
        } elseif ($period === 'weekly') {
            [$from, $to] = ReportsAnalytics::weekRange($date);
            $section['rows'] = $reports->personDays($person->id, $from, $to, false);
            $range = Printout::range($from, $to);
            $periodLabel = date('M j', strtotime($from)).' – '.date('M j, Y', strtotime($to));
        } else {
            $from = date('Y-m-01', strtotime($date));
            $to = date('Y-m-t', strtotime($date));
            $section['rows'] = $reports->personMonth($person->id, $from, $to);
            $section['monthSummary'] = collect(MonthlySummary::build($from, $to))->firstWhere('id', $person->id);
            $range = Printout::month($date);
            $periodLabel = date('F Y', strtotime($date));
        }

        [$report, $heading] = self::PERIODS[$period];
        Printout::log($report, $range, $person);

        return response()->view('reports.attendance-print', $section + Printout::stamp($request->user()) + [
            'printTitle' => Printout::title($request->user(), $report, $range, $person?->fullname),
            'reportTitle' => $heading,
            'periodLabel' => $periodLabel,
        ])->header('Cache-Control', 'no-store');
    }
}
