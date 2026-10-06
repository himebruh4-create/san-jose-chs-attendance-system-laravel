<?php

namespace App\Http\Controllers\Principal;

use App\Domain\PersonnelDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Read-only personnel list (was principal/teacher-list.php). */
class PersonnelController extends Controller
{
    public function show(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $list = PersonnelDirectory::page($search, (int) $request->query('page', 1), 10, true);

        return view('principal.personnel', [
            'search' => $search,
            'teachers' => $list['rows'],
            'page' => $list['page'],
            'limit' => 10,
            'totalPages' => $list['totalPages'],
            'stats' => PersonnelDirectory::stats(),
        ]);
    }
}
