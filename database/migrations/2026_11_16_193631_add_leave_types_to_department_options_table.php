<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Leave types become a managed dropdown list (Settings -> Dropdown Lists),
// stored with the Positions in department_options as option_type
// 'Leave Type', one row per personnel type ('Teaching' / 'Non-Teaching').
// The lists App\Domain\LeaveTypes used to hard-code are copied in, in the
// same order. "Other" is never stored: the form always adds it last.
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const DEFAULTS = [
        'Teaching' => [
            'Sick Leave', 'Vacation Leave', 'Wellness Leave', 'Emergency Leave', 'Maternity Leave', 'Paternity Leave',
        ],
        'Non-Teaching' => [
            'Sick Leave', 'Vacation Leave', 'Forced Leave', 'Special Privilege Leave', 'Wellness Leave',
            'Emergency Leave', 'Maternity Leave', 'Paternity Leave',
        ],
    ];

    public function up(): void
    {
        DB::statement("ALTER TABLE department_options MODIFY option_type ENUM('Subject', 'Position', 'Leave Type') NOT NULL");

        if (DB::table('department_options')->where('option_type', 'Leave Type')->exists()) {
            return;
        }

        $rows = [];

        foreach (self::DEFAULTS as $personnelType => $names) {
            foreach ($names as $name) {
                $rows[] = ['option_type' => 'Leave Type', 'personnel_type' => $personnelType, 'option_name' => $name, 'status' => 'Active'];
            }
        }

        DB::table('department_options')->insert($rows);
    }

    public function down(): void
    {
        DB::table('department_options')->where('option_type', 'Leave Type')->delete();
        DB::statement("ALTER TABLE department_options MODIFY option_type ENUM('Subject', 'Position') NOT NULL");
    }
};
