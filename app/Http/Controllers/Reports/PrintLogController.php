<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Support\Printout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Audit Log entry for a printout the browser builds (Weekly Roster,
 * Attendance Monitoring, DTRs, ID badges, recovery codes). Printouts the
 * server builds are logged by their own controllers.
 */
class PrintLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $role = $request->user()->role;
        $reportsForRole = array_keys(array_filter(Printout::REPORTS, fn (array $roles) => in_array($role, $roles, true)));

        $data = $request->validate([
            'report' => ['required', 'string', Rule::in($reportsForRole)],
            'range' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}(-\d{2}( to \d{4}-\d{2}-\d{2})?)?$/'],
            'teacher_id' => ['nullable', 'integer'],
        ]);

        $person = isset($data['teacher_id']) ? Teacher::query()->find($data['teacher_id'], ['id', 'fullname']) : null;

        Printout::log($data['report'], $data['range'] ?? null, $person);

        return $this->ok();
    }
}
