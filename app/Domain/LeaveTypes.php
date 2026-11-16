<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;

/**
 * The leave types the Super Admin can record (Personnel -> Add Leave), by
 * personnel type (teachers.academic_status). A managed dropdown list, kept
 * with the Positions in department_options (option_type 'Leave Type') and
 * edited in Settings -> Dropdown Lists. The one list the form, its
 * validation and every label use: the type is stored as this text in
 * teacher_leaves.leave_type and shown as-is wherever leave appears.
 * Leaves recorded before a type was added or removed keep their text, and
 * a type a leave uses cannot be removed.
 *
 * "Other" is never stored: it is always offered last, for a typed type.
 */
class LeaveTypes
{
    public const OPTION_TYPE = 'Leave Type';

    public const OTHER = 'Other';

    /** teachers.academic_status => department_options.personnel_type. */
    public const PERSONNEL_TYPES = [
        'Academic' => 'Teaching',
        'Non-Academic' => 'Non-Teaching',
    ];

    /**
     * The active types for a personnel type, in the order they were added,
     * then "Other". A person whose type is not set gets every active type.
     *
     * @return list<string>
     */
    public static function for(?string $academicStatus): array
    {
        return self::options()[array_key_exists((string) $academicStatus, self::PERSONNEL_TYPES) ? $academicStatus : ''];
    }

    public static function allows(?string $academicStatus, string $leaveType): bool
    {
        return in_array($leaveType, self::for($academicStatus), true);
    }

    /** True once any leave record (any status) carries this type. */
    public static function isUsed(string $leaveType): bool
    {
        return DB::table('teacher_leaves')->where('leave_type', $leaveType)->exists();
    }

    /**
     * For the form: personnel type => leave types, '' for a person whose
     * type is not set. Read once per request (kept on the request, so a
     * later request always sees a type just added or removed).
     *
     * @return array<string, list<string>>
     */
    public static function options(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::class)) {
            $attributes->set(self::class, self::load());
        }

        return $attributes->get(self::class);
    }

    /** @return array<string, list<string>> */
    private static function load(): array
    {
        $rows = DB::table('department_options')
            ->where('option_type', self::OPTION_TYPE)
            ->where('status', 'Active')
            ->orderBy('id')
            ->get(['personnel_type', 'option_name']);

        $options = [];

        foreach (self::PERSONNEL_TYPES as $academicStatus => $personnelType) {
            $options[$academicStatus] = self::withOther($rows->where('personnel_type', $personnelType)->pluck('option_name'));
        }

        $options[''] = self::withOther($rows->pluck('option_name'));

        return $options;
    }

    /**
     * @param  iterable<string>  $names
     * @return list<string>
     */
    private static function withOther(iterable $names): array
    {
        $names = array_values(array_unique(array_filter(
            is_array($names) ? $names : iterator_to_array($names, false),
            fn (string $name) => $name !== self::OTHER,
        )));

        return [...$names, self::OTHER];
    }
}
