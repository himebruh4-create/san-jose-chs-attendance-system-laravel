<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\ReportsAnalytics;
use App\Domain\PersonnelDirectory;
use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Support\Printout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable Absence Report (Reports -> Print Absences): confirmed
 * absences in one Reports tab's range (a day, a Mon-Sat week or a month),
 * for All Personnel or one person. The Reports page fetches this HTML and
 * prints it. No scan photos.
 */
class AbsenceReportController extends Controller
{
    /** Longest range accepted (a month). */
    private const MAX_DAYS = 31;

    public function __invoke(Request $request): Response
    {
        $from = $this->date($request->string('from')->toString());
        $to = $this->date($request->string('to')->toString());
        $period = in_array($request->query('period'), ['daily', 'weekly', 'monthly'], true) ? $request->query('period') : 'daily';

        abort_unless($from && $to && $from <= $to, 422, 'Invalid date range.');
        abort_if((strtotime($to) - strtotime($from)) / 86400 + 1 > self::MAX_DAYS, 422, 'The range is too long.');

        $person = $request->filled('teacher_id')
            ? Teacher::query()->active()->find((int) $request->query('teacher_id'), ['id', 'id_number', 'fullname', 'department', 'academic_status'])
            : null;

        $range = $period === 'monthly' ? Printout::month($from) : Printout::range($from, $to);
        Printout::log('Absence Report', $range, $person);

        return response()->view('reports.absences', Printout::stamp($request->user()) + [
            'printTitle' => Printout::title($request->user(), 'Absence Report', $range, $person?->fullname),
            'periodLabel' => $this->periodLabel($period, $from, $to),
            'person' => $person,
            'typeLabels' => PersonnelDirectory::TYPE_LABELS,
            'absences' => (new ReportsAnalytics(date('Y-m-d')))->absences($from, $to, $person?->id),
        ])->header('Cache-Control', 'no-store');
    }

    /** "Monday, October 19, 2026" / "Oct 19 – Oct 24, 2026" / "October 2026" */
    private function periodLabel(string $period, string $from, string $to): string
    {
        return match ($period) {
            'monthly' => date('F Y', strtotime($from)),
            'weekly' => date('M j', strtotime($from)).' – '.date('M j, Y', strtotime($to)),
            default => date('l, F j, Y', strtotime($from)),
        };
    }
}
