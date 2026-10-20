<?php

namespace App\Http\Controllers\Principal;

use App\Domain\PersonnelDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only personnel list (was principal/teacher-list.php). */
class PersonnelController extends Controller
{
    public function show(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $filterAcademic = trim((string) $request->query('academic_status'));
        $filterEmployment = trim((string) $request->query('employment_type'));
        $list = PersonnelDirectory::page($search, (int) $request->query('page', 1), $filterAcademic, $filterEmployment);

        return view('principal.personnel', [
            'search' => $search,
            'filterAcademic' => $filterAcademic,
            'filterEmployment' => $filterEmployment,
            'teachers' => $list['rows'],
            'page' => $list['page'],
            'limit' => PersonnelDirectory::PER_PAGE,
            'totalPages' => $list['totalPages'],
            'stats' => PersonnelDirectory::stats(),
        ]);
    }
}
