<?php

namespace App\Http\Controllers;

use App\Domain\Attendance\ReportsAnalytics;
use Illuminate\Http\Request;

/**
 * Reports & Analytics: weekly roster and monthly totals. One page for all
 * three roles (the native-PHP system had a copy per role); the Principal's
 * version adds a Print Monthly Summary Report button.
 */
abstract class ReportsController extends Controller
{
    abstract protected function role(): string;

    public function show(Request $request)
    {
        $month = (int) $request->query('month', date('m'));
        $year = (int) $request->query('year', date('Y'));

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            [$month, $year] = [(int) date('m'), (int) date('Y')];
        }

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $reports = new ReportsAnalytics(date('Y-m-d'));

        return view('reports', [
            'role' => $this->role(),
            'activeTab' => $request->query('tab') === 'monthly' ? 'monthly' : 'weekly',
            'month' => $month,
            'year' => $year,
            'monthStart' => $monthStart,
            'weekly' => $reports->weekly(),
            'monthly' => $reports->monthly($monthStart, $monthEnd),
        ]);
    }
}
