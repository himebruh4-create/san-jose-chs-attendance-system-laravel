<?php

namespace App\Domain;

/**
 * The leave types the Super Admin can record (Personnel -> Add Leave), by
 * personnel type (teachers.academic_status). The one list the form, its
 * validation and every label use: the type is stored as this text in
 * teacher_leaves.leave_type and shown as-is wherever leave appears.
 * Leaves recorded before a type was added or removed keep their text.
 */
class LeaveTypes
{
    /** Every personnel, and anyone whose type is not set. */
    public const COMMON = [
        'Sick Leave', 'Vacation Leave', 'Emergency Leave', 'Maternity Leave', 'Paternity Leave', 'Other',
    ];

    /** personnel type => its leave types, in dropdown order. */
    public const BY_PERSONNEL_TYPE = [
        'Academic' => [
            'Sick Leave', 'Vacation Leave', 'Wellness Leave', 'Emergency Leave', 'Maternity Leave', 'Paternity Leave', 'Other',
        ],
        'Non-Academic' => [
            'Sick Leave', 'Vacation Leave', 'Forced Leave', 'Special Privilege Leave', 'Wellness Leave',
            'Emergency Leave', 'Maternity Leave', 'Paternity Leave', 'Other',
        ],
    ];

    /** @return list<string> */
    public static function for(?string $academicStatus): array
    {
        return self::BY_PERSONNEL_TYPE[$academicStatus] ?? self::COMMON;
    }

    public static function allows(?string $academicStatus, string $leaveType): bool
    {
        return in_array($leaveType, self::for($academicStatus), true);
    }

    /**
     * For the form: personnel type => leave types, '' for a person whose type
     * is not set.
     *
     * @return array<string, list<string>>
     */
    public static function options(): array
    {
        return self::BY_PERSONNEL_TYPE + ['' => self::COMMON];
    }
}
