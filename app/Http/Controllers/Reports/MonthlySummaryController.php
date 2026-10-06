<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\MonthlySummary;
use App\Http\Controllers\Controller;
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

        $roleLabels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'principal' => 'Principal'];
        $account = $request->user();

        return response()->view('reports.monthly-summary', [
            'summaryReports' => MonthlySummary::build($startDate, $endDate),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'monthLabel' => date('F Y', strtotime($startDate)),
            'printedBy' => $account->email ?: ($roleLabels[$account->role] ?? 'User'),
            'printDate' => date('l, F j, Y'),
            'printTime' => date('h:i:s A'),
        ])->header('Cache-Control', 'no-store');
    }
}
