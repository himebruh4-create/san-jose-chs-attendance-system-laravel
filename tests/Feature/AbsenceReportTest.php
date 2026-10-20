<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Reports -> Print Absences: confirmed absences only, in the tab's range,
 * for All Personnel or one person.
 */
class AbsenceReportTest extends TestCase
{
    private const WEEK = ['period' => 'weekly', 'from' => '2026-10-05', 'to' => '2026-10-10'];

    private function person(array $attributes = []): Teacher
    {
        return $this->teacher($attributes + ['created_at' => '2026-01-01 00:00:00']);
    }

    private function confirmAbsent(Teacher $teacher, string $date): void
    {
        DB::table('confirmed_absences')->insert(['teacher_id' => $teacher->id, 'absence_date' => $date, 'confirmed_by' => 'tests']);
    }

    public function test_only_confirmed_absences_on_working_days_are_listed(): void
    {
        $ana = $this->person(['fullname' => 'Ana Reyes']);
        $this->confirmAbsent($ana, '2026-10-05');
        // Tue 10-06: no scan, not confirmed -> Pending Review.
        DB::table('teacher_leaves')->insert(['teacher_id' => $ana->id, 'leave_type' => 'Sick Leave', 'leave_from' => '2026-10-07', 'leave_until' => '2026-10-07']);
        DB::table('attendance')->insert(['teacher_id' => $ana->id, 'date' => '2026-10-08', 'am_arrival' => '06:55:00']);
        $this->confirmAbsent($ana, '2026-10-11');

        $response = $this->actingAs($this->account('superadmin'))->get(route('reports.absences', ['period' => 'monthly', 'from' => '2026-10-01', 'to' => '2026-10-11']));

        $response->assertViewHas('absences', fn (array $rows) => $rows === [[
            'id' => $ana->id, 'fullname' => 'Ana Reyes', 'id_number' => $ana->id_number,
            'department' => 'Teacher I', 'academic_status' => 'Academic', 'dates' => ['2026-10-05'],
        ]]);
        $response->assertSee('Absence Report &ndash; October 2026', false)->assertSee('Oct 5');
    }

    public function test_the_range_is_respected(): void
    {
        $ana = $this->person();
        $this->confirmAbsent($ana, '2026-10-02');
        $this->confirmAbsent($ana, '2026-10-06');
        $this->confirmAbsent($ana, '2026-10-12');

        $response = $this->actingAs($this->account('admin'))->get(route('reports.absences', self::WEEK));

        $response->assertViewHas('absences', fn (array $rows) => $rows[0]['dates'] === ['2026-10-06'])
            ->assertSee('Absence Report &ndash; Oct 5 – Oct 10, 2026', false);
    }

    public function test_one_person_lists_only_their_absent_dates(): void
    {
        $ana = $this->person(['fullname' => 'Ana Reyes']);
        $ben = $this->person(['fullname' => 'Ben Cruz']);
        $this->confirmAbsent($ana, '2026-10-05');
        $this->confirmAbsent($ana, '2026-10-09');
        $this->confirmAbsent($ben, '2026-10-06');

        $response = $this->actingAs($this->account('principal'))->get(route('reports.absences', self::WEEK + ['teacher_id' => $ana->id]));

        $response->assertViewHas('absences', fn (array $rows) => array_column($rows, 'fullname') === ['Ana Reyes'])
            ->assertSeeInOrder(['Ana Reyes', 'Monday, October 5, 2026', 'Friday, October 9, 2026', 'Total days absent'])
            ->assertDontSee('Ben Cruz');
    }

    public function test_a_period_without_absences_says_so(): void
    {
        $this->person();

        $response = $this->actingAs($this->account('superadmin'))->get(route('reports.absences', ['period' => 'daily', 'from' => '2026-10-06', 'to' => '2026-10-06']));

        $response->assertSee('No absences recorded for this period.')
            ->assertSee('Absence Report &ndash; Tuesday, October 6, 2026', false);
    }

    #[TestWith(['from' => '2026-10-10', 'to' => '2026-10-05'])]
    #[TestWith(['from' => '2026-09-01', 'to' => '2026-10-31'])]
    #[TestWith(['from' => 'yesterday', 'to' => '2026-10-05'])]
    public function test_an_invalid_or_too_long_range_is_refused(string $from, string $to): void
    {
        $response = $this->actingAs($this->account('admin'))->get(route('reports.absences', ['period' => 'weekly', 'from' => $from, 'to' => $to]));

        $response->assertUnprocessable();
    }

    public function test_guests_cannot_open_it(): void
    {
        $this->account('superadmin');

        $this->get(route('reports.absences', self::WEEK))->assertRedirect('/login');
    }
}
