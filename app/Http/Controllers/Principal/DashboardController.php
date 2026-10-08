<?php

namespace App\Http\Controllers\Principal;

use App\Domain\Attendance\DashboardStats;
use App\Domain\Attendance\ScanVerificationCounts;
use App\Domain\Attendance\ScheduleChanges;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function show()
    {
        $today = date('Y-m-d');
        $stats = new DashboardStats;
        $todayStats = $stats->today($today);

        return response()->view('principal.dashboard', [
            'today' => $today,
            'totalTeachers' => $stats->totalPersonnel(),
            'todayCounts' => $todayStats['counts'],
            'teachersOnLeaveToday' => $todayStats['on_leave'],
            'todaysEvent' => $stats->todaysEvent($today),
            'flaggedTeachers' => $stats->flaggedAbsences($today),
            'scheduleChanges' => ScheduleChanges::forDisplay($stats->events(), $today, $stats->teachers()),
            'scanVerification' => ScanVerificationCounts::get(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
