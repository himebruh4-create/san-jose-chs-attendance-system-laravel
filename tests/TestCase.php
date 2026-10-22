<?php

namespace Tests;

use App\Models\Account;
use App\Models\KioskDevice;
use App\Models\Teacher;
use App\Support\SecurityQuestions;
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

    /**
     * Tests empty the database before each run. When the configuration is
     * cached (`php artisan optimize`), phpunit.xml's test database is
     * ignored and the real one would be used, so refuse to touch any
     * database whose name does not end in "_test".
     */
    protected function beforeRefreshingDatabase()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException(
                "Refusing to run tests against \"{$database}\": tests wipe the database they use. "
                .'Run `php artisan optimize:clear` (or `composer test`) and make sure phpunit.xml points at a *_test database.'
            );
        }
    }

    /** Questions and answers every test Super Admin starts with. */
    protected const SECURITY_QUESTIONS = ['childhood_best_friend', 'grade1_street', 'first_concert'];

    protected const SECURITY_ANSWERS = ['Juan Dela Cruz', 'Sto. Niño Street', 'Eraserheads 1997'];

    /**
     * A login. A Super Admin gets the 3 security questions above, like a
     * finished setup, unless $withSecurityQuestions is false (they are then
     * kept on My Account until they set them).
     */
    protected function account(string $role = 'superadmin', array $attributes = [], bool $withSecurityQuestions = true): Account
    {
        static $n = 0;
        $n++;

        $account = Account::create($attributes + [
            'full_name' => ucfirst($role)." User {$n}",
            'email' => "{$role}{$n}@test.local",
            'password' => 'Secret#2026',
            'role' => $role,
        ]);

        if ($role === 'superadmin' && $withSecurityQuestions) {
            SecurityQuestions::save($account->id, self::SECURITY_QUESTIONS, self::SECURITY_ANSWERS);
        }

        return $account;
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
