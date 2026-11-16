<?php

namespace App\Http\Controllers\Data;

use App\Domain\LeaveTypes;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Managed dropdown lists (Super Admin, Settings -> Dropdown Lists): Subject,
 * Position and Leave Type values. Ported from
 * dtr/get-department-options.php, save-department-option.php and
 * deactivate-department-option.php; leave types are new.
 *
 * Positions and leave types each belong to a Personnel Type. A position
 * name is unique across both types; a leave type is unique within its type
 * (Sick Leave is on both lists). A leave type any leave record uses cannot
 * be deactivated (or permanently deleted from the Recycle Bin).
 */
class DepartmentOptionController extends Controller
{
    public const TYPES = ['Subject', 'Position', LeaveTypes::OPTION_TYPE];

    /** Option types that belong to a Personnel Type. */
    private const BY_PERSONNEL_TYPE = ['Position', LeaveTypes::OPTION_TYPE];

    public const PERSONNEL_TYPES = ['Teaching', 'Non-Teaching'];

    public function index(Request $request): JsonResponse
    {
        $type = trim((string) $request->query('type'));
        // The Add/Edit Personnel form and the Settings tabs narrow by Personnel Type.
        $personnelType = trim((string) $request->query('personnel_type'));

        if (! in_array($type, self::TYPES, true)) {
            return $this->fail('Invalid or missing type. Must be "Subject", "Position" or "Leave Type".');
        }

        if ($personnelType !== '' && ! in_array($personnelType, self::PERSONNEL_TYPES, true)) {
            return $this->fail('Invalid personnel_type.');
        }

        $options = DB::table('department_options')
            ->select('id', 'option_type', 'personnel_type', 'option_name', 'status', 'created_at')
            ->where('option_type', $type)
            ->where('status', 'Active')
            ->when($personnelType !== '', fn ($q) => $q->where('personnel_type', $personnelType))
            // Leave types keep the order they were added (the Add Leave
            // dropdown's order); the other lists are alphabetical.
            ->when($type === LeaveTypes::OPTION_TYPE,
                fn ($q) => $q->orderBy('id'),
                fn ($q) => $q->orderByRaw("CASE WHEN option_name = 'Other' THEN 1 ELSE 0 END")->orderBy('option_name'))
            ->get();

        return $this->ok(['options' => $options]);
    }

    public function store(Request $request): JsonResponse
    {
        $optionType = trim((string) $request->input('option_type'));
        $optionName = SchoolEventController::cleanName((string) $request->input('option_name'));
        $personnelType = trim((string) $request->input('personnel_type'));
        $label = strtolower($optionType);

        if (! in_array($optionType, self::TYPES, true)) {
            return $this->fail('Invalid option type.');
        }

        if ($optionName === '') {
            return $this->fail('Please enter a name.');
        }

        if (mb_strlen($optionName) > 100) {
            return $this->fail('The name is too long (100 characters maximum).');
        }

        if ($optionType === LeaveTypes::OPTION_TYPE && strcasecmp($optionName, LeaveTypes::OTHER) === 0) {
            return $this->fail('"Other" is always offered in the Add Leave form. Please enter a different name.');
        }

        if (in_array($optionType, self::BY_PERSONNEL_TYPE, true)) {
            if (! in_array($personnelType, self::PERSONNEL_TYPES, true)) {
                return $this->fail("Please select a Personnel Type for this {$label}.");
            }
        } else {
            $personnelType = null;
        }

        $duplicate = DB::table('department_options')
            ->where('option_type', $optionType)
            ->where('option_name', $optionName)
            ->where('status', 'Active')
            ->when($optionType === LeaveTypes::OPTION_TYPE, fn ($q) => $q->where('personnel_type', $personnelType))
            ->exists();

        if ($duplicate) {
            return $this->fail("This {$label} already exists.");
        }

        $id = DB::table('department_options')->insertGetId([
            'option_type' => $optionType,
            'option_name' => $optionName,
            'status' => 'Active',
            'personnel_type' => $personnelType,
        ]);

        Audit::log('department_option.created', 'department_option', $id, ['type' => $optionType, 'name' => $optionName]);

        return $this->ok(['message' => ucfirst($label).' added successfully.', 'id' => $id]);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $id = (int) $request->input('id');

        if ($id <= 0) {
            return $this->fail('Invalid option ID.');
        }

        $option = DB::table('department_options')->where('id', $id)->first(['option_type', 'option_name']);

        if ($option && $option->option_type === LeaveTypes::OPTION_TYPE && LeaveTypes::isUsed($option->option_name)) {
            return $this->fail(self::leaveTypeInUseMessage($option->option_name));
        }

        DB::table('department_options')->where('id', $id)->update(['status' => 'Inactive']);
        Audit::log('department_option.deactivated', 'department_option', $id);

        return $this->ok(['message' => 'Option deactivated successfully.']);
    }

    public static function leaveTypeInUseMessage(string $name): string
    {
        return "{$name} is used by a leave record, so it cannot be removed.";
    }
}
