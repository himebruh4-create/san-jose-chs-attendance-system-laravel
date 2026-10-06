<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Attendance\DashboardStats;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function show()
    {
        $today = date('Y-m-d');
        $stats = new DashboardStats;
        $todayStats = $stats->today($today);

        // Admin / Principal password reset requests (resolved in Settings).
        $resetRequests = DB::table('password_reset_requests as prr')
            ->join('accounts as a', 'a.id', '=', 'prr.account_id')
            ->where('prr.status', 'pending')
            ->where('a.is_deleted', 0)
            ->orderBy('prr.requested_at')
            ->get(['prr.requested_at', 'a.full_name', 'a.email', 'a.role']);

        return response()->view('superadmin.dashboard', [
            'today' => $today,
            'totalPersonnel' => $stats->totalPersonnel(),
            'employmentTypeCounts' => $stats->employmentTypeCounts(),
            'todayCounts' => $todayStats['counts'],
            'teachersOnLeaveToday' => $todayStats['on_leave'],
            'week' => $stats->week($today),
            'resetRequests' => $resetRequests,
            'liveLogs' => DashboardStats::liveLogs($today),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
