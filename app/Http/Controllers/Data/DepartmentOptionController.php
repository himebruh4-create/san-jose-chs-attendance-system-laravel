<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Subject / Position dropdown values (Super Admin). Ported from
 * dtr/get-department-options.php, save-department-option.php and
 * deactivate-department-option.php.
 */
class DepartmentOptionController extends Controller
{
    public function index(Request $request)
    {
        $type = trim((string) $request->query('type'));
        // Only the Add/Edit Personnel form narrows Positions by Personnel Type.
        $personnelType = trim((string) $request->query('personnel_type'));

        if (! in_array($type, ['Subject', 'Position'], true)) {
            return $this->fail('Invalid or missing type. Must be "Subject" or "Position".');
        }

        if ($personnelType !== '' && ! in_array($personnelType, ['Teaching', 'Non-Teaching'], true)) {
            return $this->fail('Invalid personnel_type.');
        }

        $options = DB::table('department_options')
            ->select('id', 'option_type', 'personnel_type', 'option_name', 'status', 'created_at')
            ->where('option_type', $type)
            ->where('status', 'Active')
            ->when($personnelType !== '', fn ($q) => $q->where('personnel_type', $personnelType))
            ->orderByRaw("CASE WHEN option_name = 'Other' THEN 1 ELSE 0 END")
            ->orderBy('option_name')
            ->get();

        return $this->ok(['options' => $options]);
    }

    public function store(Request $request)
    {
        $optionType = trim((string) $request->input('option_type'));
        $optionName = SchoolEventController::cleanName((string) $request->input('option_name'));
        $personnelType = trim((string) $request->input('personnel_type'));

        if (! in_array($optionType, ['Subject', 'Position'], true)) {
            return $this->fail('Invalid option type.');
        }

        if ($optionName === '') {
            return $this->fail('Please enter a name.');
        }

        if (mb_strlen($optionName) > 100) {
            return $this->fail('The name is too long (100 characters maximum).');
        }

        // Only Positions are categorized by Personnel Type.
        if ($optionType === 'Position') {
            if (! in_array($personnelType, ['Teaching', 'Non-Teaching'], true)) {
                return $this->fail('Please select a Personnel Type for this position.');
            }
        } else {
            $personnelType = null;
        }

        $duplicate = DB::table('department_options')
            ->where('option_type', $optionType)
            ->where('option_name', $optionName)
            ->where('status', 'Active')
            ->exists();

        if ($duplicate) {
            return $this->fail('This '.strtolower($optionType).' already exists.');
        }

        $id = DB::table('department_options')->insertGetId([
            'option_type' => $optionType,
            'option_name' => $optionName,
            'status' => 'Active',
            'personnel_type' => $personnelType,
        ]);

        Audit::log('department_option.created', 'department_option', $id, ['type' => $optionType, 'name' => $optionName]);

        return $this->ok(['message' => ucfirst(strtolower($optionType)).' added successfully.', 'id' => $id]);
    }

    public function deactivate(Request $request)
    {
        $id = (int) $request->input('id');

        if ($id <= 0) {
            return $this->fail('Invalid option ID.');
        }

        DB::table('department_options')->where('id', $id)->update(['status' => 'Inactive']);
        Audit::log('department_option.deactivated', 'department_option', $id);

        return $this->ok(['message' => 'Option deactivated successfully.']);
    }
}
