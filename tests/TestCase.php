<?php

namespace Tests;

use App\Models\Account;
use App\Models\KioskDevice;
use App\Models\Teacher;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingsSeeder::class);
    }

    protected function account(string $role = 'superadmin', array $attributes = []): Account
    {
        static $n = 0;
        $n++;

        return Account::create($attributes + [
            'full_name' => ucfirst($role)." User {$n}",
            'email' => "{$role}{$n}@test.local",
            'password' => 'Secret#2026',
            'role' => $role,
        ]);
    }

    /** A personnel record with a Mon–Fri schedule (default 07:00–15:00). */
    protected function teacher(array $attributes = [], string $in = '07:00:00', string $out = '15:00:00', array $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']): Teacher
    {
        static $n = 0;
        $n++;

        $teacher = new Teacher;
        $teacher->forceFill($attributes + [
            'id_number' => sprintf('2026-%04d', 9000 + $n),
            'fullname' => "Test Person {$n}",
            'department' => 'Teacher I',
            'academic_status' => 'Academic',
            'employment_type' => 'Regular',
            'barcode' => (string) (500000 + $n),
        ])->save();

        foreach ($days as $day) {
            DB::table('teacher_schedules')->insert(['teacher_id' => $teacher->id, 'day' => $day, 'time_in' => $in, 'time_out' => $out]);
        }

        return $teacher;
    }

    /** Cookie value of a freshly registered kiosk device. */
    protected function kioskToken(): string
    {
        [, $token] = KioskDevice::register('Test kiosk', 'tests');

        return $token;
    }
}
