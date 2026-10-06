<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PersonnelDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Read-only personnel list (was admin/admin-personnel-management.php). */
class PersonnelController extends Controller
{
    public function show(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $list = PersonnelDirectory::page($search, (int) $request->query('page', 1), 5, false);

        return view('admin.personnel', [
            'search' => $search,
            'teacherList' => $list['rows'],
            'page' => $list['page'],
            'limit' => 5,
            'totalPages' => $list['totalPages'],
            'stats' => PersonnelDirectory::stats(),
        ]);
    }
}
