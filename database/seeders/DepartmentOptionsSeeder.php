<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentOptionsSeeder extends Seeder
{
    /**
     * Default Subject / Position dropdown values for a fresh install. Only
     * runs when there are none yet, so it never touches imported data. (The
     * default leave types come from their migration, so they are not
     * counted here.)
     */
    public function run(): void
    {
        if (DB::table('department_options')->whereIn('option_type', ['Subject', 'Position'])->exists()) {
            return;
        }

        $subjects = [
            'English', 'Filipino', 'Mathematics', 'Science', 'Araling Panlipunan (AP)',
            'Edukasyon sa Pagpapakatao (EsP)', 'MAPEH', 'TLE', 'Computer / ICT',
        ];

        $teaching = [
            'Teacher I', 'Teacher II', 'Teacher III', 'Head Teacher I', 'Head Teacher III',
            'Master Teacher I', 'Master Teacher II',
        ];

        $nonTeaching = [
            'Principal IV', 'Asst. Principal II', 'School Registrar', 'Guidance Counselor', 'Librarian',
            'School Nurse', 'Accounting / Finance Staff', 'IT Support Staff', 'Utility / Maintenance',
            'Security Guard I', 'Administrative Officer I (Cashier I)', 'Administrative Officer I (Supply Officer I)',
            'Administrative Officer IV', 'Administrative Aide I', 'Administrative Aide I (Casual)',
            'Administrative Aide III', 'Administrative Aide IV',
        ];

        $rows = [];

        foreach ($subjects as $name) {
            $rows[] = ['option_type' => 'Subject', 'personnel_type' => null, 'option_name' => $name, 'status' => 'Active'];
        }

        foreach ($teaching as $name) {
            $rows[] = ['option_type' => 'Position', 'personnel_type' => 'Teaching', 'option_name' => $name, 'status' => 'Active'];
        }

        foreach ($nonTeaching as $name) {
            $rows[] = ['option_type' => 'Position', 'personnel_type' => 'Non-Teaching', 'option_name' => $name, 'status' => 'Active'];
        }

        DB::table('department_options')->insert($rows);
    }
}
