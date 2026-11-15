<?php

namespace Tests\Feature;

use App\Domain\Attendance\MonthlySummary;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Holidays and other School Schedule Changes never count as absent, nor do
 * days a person does not work; the Monthly Report shows the changes on
 * their days, apart from the status counts.
 */
class HolidayNotAbsentTest extends TestCase
{
    private function person(string $name = 'Ana Cruz'): Teacher
    {
        return $this->teacher(['fullname' => $name, 'created_at' => '2026-01-01 00:00:00']);
    }

    /** A whole-day change on 2026-10-12 (a Monday); $workerIds are scheduled to work through it. */
    private function holiday(array $workerIds = [], string $date = '2026-10-12'): int
    {
        $id = DB::table('school_events')->insertGetId([
            'event_name' => 'Founding Anniversary', 'event_type' => 'Holiday', 'date_from' => $date, 'date_to' => $date,
            'remark' => 'No classes and no office work.',
        ]);

        foreach ($workerIds as $teacherId) {
            DB::table('school_event_personnel')->insert(['school_event_id' => $id, 'teacher_id' => $teacherId]);
        }

        return $id;
    }

    public function test_an_absence_stored_on_a_holiday_is_not_counted_as_absent(): void
    {
        $ana = $this->person();
        $this->holiday();
        DB::table('confirmed_absences')->insert(['teacher_id' => $ana->id, 'absence_date' => '2026-10-12']);

        $this->assertSame(0, MonthlySummary::build('2026-10-01', '2026-10-31')[0]['absent_count']);

        $response = $this->actingAs($this->account('admin'))->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-10']));

        $response->assertViewHas('monthly', fn (array $monthly) => $monthly['lists']['Absent'] === []);
        $this->assertSame([], $response->viewData('calendar')['days']['2026-10-12']['counts']);
        $this->get(route('reports.absences', ['period' => 'monthly', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertViewHas('absences', []);
    }

    public function test_an_absence_cannot_be_confirmed_on_a_holiday(): void
    {
        $ana = $this->person();
        $this->holiday();

        $this->actingAs($this->account('superadmin'))
            ->postJson('/data/confirm-absent', ['teacher_id' => $ana->id, 'date' => '2026-10-12'])
            ->assertExactJson(['success' => false, 'message' => 'Cannot confirm an absence on a holiday or a no-work day.']);

        $this->assertDatabaseCount('confirmed_absences', 0);
    }

    public function test_an_absence_cannot_be_confirmed_on_a_day_the_person_does_not_work(): void
    {
        $ana = $this->person();

        // 2026-10-10 is a Saturday; the schedule is Monday to Friday.
        $this->actingAs($this->account('admin'))
            ->postJson('/data/confirm-absent', ['teacher_id' => $ana->id, 'date' => '2026-10-10'])
            ->assertExactJson(['success' => false, 'message' => 'Cannot confirm an absence on a holiday or a no-work day.']);

        $this->assertDatabaseCount('confirmed_absences', 0);
    }

    public function test_an_absence_cannot_be_confirmed_for_a_future_date(): void
    {
        $ana = $this->person();

        $this->actingAs($this->account('superadmin'))
            ->postJson('/data/confirm-absent', ['teacher_id' => $ana->id, 'date' => date('Y-m-d', strtotime('+1 day'))])
            ->assertExactJson(['success' => false, 'message' => 'Cannot confirm an absence for a future date.']);

        $this->assertDatabaseCount('confirmed_absences', 0);
    }

    public function test_personnel_scheduled_to_work_through_a_holiday_can_still_be_absent(): void
    {
        $ana = $this->person();
        $this->holiday([$ana->id]);

        $this->actingAs($this->account('superadmin'))
            ->postJson('/data/confirm-absent', ['teacher_id' => $ana->id, 'date' => '2026-10-12'])
            ->assertJson(['success' => true]);

        $this->assertSame(1, MonthlySummary::build('2026-10-01', '2026-10-31')[0]['absent_count']);
    }

    public function test_the_monthly_calendar_marks_a_schedule_change_with_its_details_apart_from_the_counts(): void
    {
        $ana = $this->person('Ana Cruz');
        $ben = $this->person('Ben Reyes');
        $this->holiday([$ben->id]);
        DB::table('attendance')->insert(['teacher_id' => $ben->id, 'date' => '2026-10-12', 'am_arrival' => '06:55:00']);

        $response = $this->actingAs($this->account('principal'))->get(route('principal.reports', ['tab' => 'monthly', 'monthPicker' => '2026-10']));

        $day = $response->viewData('calendar')['days']['2026-10-12'];
        $this->assertSame([[
            'id' => $day['changes'][0]['id'],
            'type' => 'Holiday',
            'is_holiday' => true,
            'name' => 'Founding Anniversary',
            'marker' => 'Holiday',
            'dates' => 'Oct 12, 2026',
            'duration' => 'Whole Day',
            'description' => 'No classes and no office work.',
            'affected' => 'Everyone except 1 personnel scheduled to work',
            'scheduled_to_work' => ['Ben Reyes'],
            'applies_to_person' => null,
        ]], $day['changes']);
        // Ben worked through it and is counted; Ana's holiday is not a status.
        $this->assertSame(['Present' => 1], $day['counts']);
        $response->assertSee('class="cal-change"', false);
    }

    public function test_one_persons_calendar_says_whether_a_schedule_change_applies_to_them(): void
    {
        $ana = $this->person('Ana Cruz');
        $ben = $this->person('Ben Reyes');
        $this->holiday([$ben->id]);

        $this->actingAs($this->account('admin'));
        $forAna = $this->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-10', 'teacher_id' => $ana->id]));
        $forBen = $this->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-10', 'teacher_id' => $ben->id]));

        $this->assertTrue($forAna->viewData('calendar')['days']['2026-10-12']['changes'][0]['applies_to_person']);
        $this->assertFalse($forBen->viewData('calendar')['days']['2026-10-12']['changes'][0]['applies_to_person']);
    }

    public function test_a_future_day_opens_only_when_it_has_a_schedule_change(): void
    {
        $this->person();
        $nextMonth = date('Y-m', strtotime('first day of next month'));
        $changeDay = date('Y-m-d', strtotime('monday', strtotime($nextMonth.'-01')));
        $this->holiday([], $changeDay);

        $response = $this->actingAs($this->account('admin'))->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => $nextMonth]));

        $response->assertSee('data-date="'.$changeDay.'"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-date='));
    }
}
