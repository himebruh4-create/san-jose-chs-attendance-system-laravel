<?php

namespace Tests\Feature;

use App\Domain\Attendance\DashboardStats;
use App\Domain\Attendance\MonthlySummary;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Working days come from the personnel's own schedules
 * (App\Domain\Attendance\WorkingDays), never a fixed Monday-to-Friday or
 * Saturday rule: the Monthly Report calendar, its day window, the Daily
 * Report and the dashboard figures agree on every day. October 2026:
 * Sat 10, Sun 11, Mon 12.
 */
class WorkingDaysTest extends TestCase
{
    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    private function weekdayPerson(string $name = 'Ana Cruz'): Teacher
    {
        return $this->teacher(['fullname' => $name, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function saturdayPerson(string $name = 'Gil Guard'): Teacher
    {
        return $this->teacher(['fullname' => $name, 'created_at' => '2026-01-01 00:00:00'], days: [...self::WEEKDAYS, 'Saturday']);
    }

    private function monthlyCalendar(?Teacher $person = null): array
    {
        return $this->actingAs($this->account('admin'))
            ->get(route('admin.reports', array_filter(['tab' => 'monthly', 'monthPicker' => '2026-10', 'teacher_id' => $person?->id])))
            ->viewData('calendar');
    }

    public function test_a_saturday_with_a_scheduled_person_is_a_working_day_and_its_attendance_counts(): void
    {
        $this->weekdayPerson();
        $guard = $this->saturdayPerson();
        DB::table('attendance')->insert(['teacher_id' => $guard->id, 'date' => '2026-10-10', 'am_arrival' => '06:55:00', 'pm_departure' => '15:00:00']);

        $saturday = $this->monthlyCalendar()['days']['2026-10-10'];

        $this->assertNull($saturday['label']);
        $this->assertSame(['Present' => 1], $saturday['counts']);
        $this->assertSame(['Gil Guard'], array_column($saturday['groups']['Present'], 'name'));
    }

    public function test_a_scheduled_saturday_without_a_scan_is_pending_review_not_no_work(): void
    {
        $this->saturdayPerson();

        $saturday = $this->monthlyCalendar()['days']['2026-10-10'];

        $this->assertSame([null, ['Pending Review' => 1]], [$saturday['label'], $saturday['counts']]);
    }

    public function test_a_scheduled_saturday_is_not_no_work_even_when_nothing_on_it_is_counted(): void
    {
        // Added after that Saturday, so nothing of it is counted for them;
        // it is still a day personnel are scheduled to work.
        $this->teacher(['fullname' => 'New Guard', 'created_at' => '2026-10-20 08:00:00'], days: [...self::WEEKDAYS, 'Saturday']);

        $saturday = $this->monthlyCalendar()['days']['2026-10-10'];

        $this->assertSame([null, []], [$saturday['label'], $saturday['counts']]);
    }

    public function test_a_saturday_with_nobody_scheduled_shows_no_work(): void
    {
        $this->weekdayPerson();

        $response = $this->actingAs($this->account('admin'))->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-10']));
        $saturday = $response->viewData('calendar')['days']['2026-10-10'];

        $this->assertSame(['No work', []], [$saturday['label'], $saturday['counts']]);
        $response->assertSee('<span class="cal-label" title="No work">No work</span>', false);
    }

    public function test_one_persons_calendar_shows_no_work_on_the_days_that_person_is_not_scheduled(): void
    {
        $ana = $this->weekdayPerson();
        $this->saturdayPerson();   // someone else works Saturdays

        $days = $this->monthlyCalendar($ana)['days'];

        $this->assertSame('No work', $days['2026-10-10']['label']);
        $this->assertNull($days['2026-10-12']['label']);
    }

    public function test_a_holiday_keeps_its_own_label_and_is_never_counted_as_absent(): void
    {
        $guard = $this->saturdayPerson();
        DB::table('school_events')->insert(['event_name' => 'Town Fiesta', 'event_type' => 'Holiday', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10']);
        DB::table('confirmed_absences')->insert(['teacher_id' => $guard->id, 'absence_date' => '2026-10-10']);

        $saturday = $this->monthlyCalendar()['days']['2026-10-10'];

        $this->assertSame(['Holiday', []], [$saturday['label'], $saturday['counts']]);
        $this->assertSame(0, MonthlySummary::build('2026-10-01', '2026-10-31')[0]['absent_count']);
    }

    public function test_a_sunday_with_nobody_scheduled_is_no_work_and_not_a_calendar_column(): void
    {
        $this->saturdayPerson();

        $calendar = $this->monthlyCalendar();
        $daily = $this->get(route('admin.reports', ['tab' => 'daily', 'date' => '2026-10-11']));

        $this->assertArrayNotHasKey('2026-10-11', $calendar['days']);
        $this->assertSame(6, $calendar['daysPerWeek']);
        $daily->assertViewHas('dailyDayLabel', ['label' => 'No work', 'labelTitle' => null])
            ->assertSee('No work: no personnel are scheduled to work on this day.');
    }

    public function test_a_sunday_someone_is_scheduled_on_is_a_calendar_working_day(): void
    {
        $this->weekdayPerson();
        $this->teacher(['fullname' => 'Sol Sunday', 'created_at' => '2026-01-01 00:00:00'], days: ['Sunday']);

        $calendar = $this->monthlyCalendar();

        $this->assertSame(7, $calendar['daysPerWeek']);
        $this->assertSame([null, ['Pending Review' => 1]], [$calendar['days']['2026-10-11']['label'], $calendar['days']['2026-10-11']['counts']]);
        $this->assertSame('No work', $calendar['days']['2026-10-10']['label']);
    }

    public function test_the_calendar_the_daily_report_and_the_dashboard_agree_on_a_saturday(): void
    {
        $this->weekdayPerson('Ana Cruz');
        $guard = $this->saturdayPerson('Gil Guard');
        $late = $this->saturdayPerson('Lou Late');
        DB::table('attendance')->insert([
            ['teacher_id' => $guard->id, 'date' => '2026-10-10', 'am_arrival' => '06:55:00', 'pm_departure' => '15:00:00'],
            ['teacher_id' => $late->id, 'date' => '2026-10-10', 'am_arrival' => '07:20:00', 'pm_departure' => '15:00:00'],
        ]);
        $admin = $this->account('admin');

        $calendarDay = $this->actingAs($admin)->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-10']))->viewData('calendar')['days']['2026-10-10'];
        $daily = $this->get(route('admin.reports', ['tab' => 'daily', 'date' => '2026-10-10']));
        $dashboardWeek = (new DashboardStats)->week('2026-10-10');
        $saturdayColumn = array_search('2026-10-10', $this->datesOf($dashboardWeek), true);

        $dailyStatuses = array_column($daily->viewData('daily'), 'status', 'fullname');
        $this->assertSame(['Present' => 1, 'Late' => 1], $calendarDay['counts']);
        $this->assertSame(['Gil Guard' => 'Present', 'Lou Late' => 'Late', 'Ana Cruz' => 'Not Scheduled'], $dailyStatuses);
        $this->assertSame([1, 1], [$dashboardWeek['data']['Present'][$saturdayColumn], $dashboardWeek['data']['Late'][$saturdayColumn]]);
        $daily->assertViewHas('dailyDayLabel', ['label' => null, 'labelTitle' => null])->assertDontSee('No work:');
    }

    public function test_absent_is_counted_only_on_a_scheduled_day_without_a_scan(): void
    {
        $ana = $this->weekdayPerson();
        DB::table('confirmed_absences')->insert([
            ['teacher_id' => $ana->id, 'absence_date' => '2026-10-10'],   // Saturday: not scheduled
            ['teacher_id' => $ana->id, 'absence_date' => '2026-10-12'],   // Monday: scanned in
            ['teacher_id' => $ana->id, 'absence_date' => '2026-10-13'],   // Tuesday: a real absence
        ]);
        DB::table('attendance')->insert(['teacher_id' => $ana->id, 'date' => '2026-10-12', 'am_arrival' => '06:58:00']);

        $days = $this->monthlyCalendar($ana)['days'];

        $this->assertSame(['Not Scheduled', 'Present', 'Absent'], [$days['2026-10-10']['status'], $days['2026-10-12']['status'], $days['2026-10-13']['status']]);
        $this->assertSame(1, MonthlySummary::build('2026-10-01', '2026-10-31')[0]['absent_count']);
    }

    public function test_an_absence_cannot_be_confirmed_on_a_day_the_person_scanned_in(): void
    {
        $ana = $this->weekdayPerson();
        DB::table('attendance')->insert(['teacher_id' => $ana->id, 'date' => '2026-10-12', 'am_arrival' => '06:58:00']);

        $this->actingAs($this->account('superadmin'))
            ->postJson('/data/confirm-absent', ['teacher_id' => $ana->id, 'date' => '2026-10-12'])
            ->assertExactJson(['success' => false, 'message' => 'Cannot confirm an absence: this personnel scanned in that day.']);

        $this->assertDatabaseCount('confirmed_absences', 0);
    }

    /** The dashboard week's dates (Monday first). */
    private function datesOf(array $week): array
    {
        $dates = [];
        for ($c = strtotime($week['start']); $c <= strtotime($week['end']); $c = strtotime('+1 day', $c)) {
            $dates[] = date('Y-m-d', $c);
        }

        return $dates;
    }
}
