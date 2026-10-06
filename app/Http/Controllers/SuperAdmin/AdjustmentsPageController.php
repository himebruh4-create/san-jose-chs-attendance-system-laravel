<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Attendance Adjustments / Pending Review / Rejected Scan Log page
 * (was superadmin/attendance-adjustments.php). The tables load through
 * the /data/adjustments, /data/pending-review and /data/rejected-scans
 * endpoints.
 */
class AdjustmentsPageController extends Controller
{
    public function show()
    {
        // Archived personnel stay selectable (a past day may still need a
        // correction) but are marked as such.
        $teachers = DB::table('teachers')
            ->orderBy('fullname')
            ->get(['id', 'id_number', 'fullname', 'is_deleted']);

        return view('superadmin.adjustments', ['teachers' => $teachers]);
    }
}
