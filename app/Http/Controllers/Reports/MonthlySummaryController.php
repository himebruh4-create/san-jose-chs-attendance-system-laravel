<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\MonthlySummary;
use App\Http\Controllers\Controller;
use App\Support\Printout;
use Illuminate\Http\Request;

/**
 * The printable Monthly Attendance Summary Report (was dtr/monthly-summary.php).
 * The attendance pages fetch this HTML and print it.
 */
class MonthlySummaryController extends Controller
{
    public function __invoke(Request $request)
    {
        $month = (int) $request->query('month', date('m'));
        $year = (int) $request->query('year', date('Y'));

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            abort(422, 'Invalid month.');
        }

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $range = Printout::month($startDate);
        Printout::log('Monthly Summary Report', $range);

        return response()->view('reports.monthly-summary', Printout::stamp($request->user()) + [
            'printTitle' => Printout::title($request->user(), 'Monthly Summary Report', $range),
            'summaryReports' => MonthlySummary::build($startDate, $endDate),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'monthLabel' => date('F Y', strtotime($startDate)),
        ])->header('Cache-Control', 'no-store');
    }
}
