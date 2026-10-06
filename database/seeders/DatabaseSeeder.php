<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * A fresh install only needs the single settings row and the default
     * dropdown options. Real data comes from `php artisan legacy:import`,
     * and the first Super Admin is created on the /setup page.
     */
    public function run(): void
    {
        $this->call(SystemSettingsSeeder::class);
        $this->call(DepartmentOptionsSeeder::class);
    }
}
