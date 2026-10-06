<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attendance\DashboardStats;
use App\Domain\Attendance\ScheduleChanges;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function show()
    {
        $today = date('Y-m-d');
        $stats = new DashboardStats;
        $todayStats = $stats->today($today);

        return response()->view('admin.dashboard', [
            'today' => $today,
            'totalPersonnel' => $stats->totalPersonnel(),
            'employmentTypeCounts' => $stats->employmentTypeCounts(),
            'todayCounts' => $todayStats['counts'],
            'teachersOnLeaveToday' => $todayStats['on_leave'],
            'week' => $stats->week($today),
            'scheduleChanges' => ScheduleChanges::forDisplay($stats->events(), $today, $stats->teachers()),
            'liveLogs' => DashboardStats::liveLogs($today),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
