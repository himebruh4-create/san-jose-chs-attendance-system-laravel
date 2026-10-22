<?php

namespace Tests\Feature;

use App\Domain\Attendance\MonthlySummary;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Reports page: Daily / Weekly / Monthly for All Personnel or one person,
 * and the Super Admin-only scan photo strip (RA 10173).
 */
class ReportsPageTest extends TestCase
{
    private function person(array $attributes = []): Teacher
    {
        return $this->teacher($attributes + ['created_at' => '2026-01-01 00:00:00']);
    }

    /** An accepted kiosk scan and its scan_photos row, as the kiosk saves them. */
    private function scan(Teacher $teacher, string $date, string $column, string $time, bool $withPhoto = true): int
    {
        DB::table('attendance')->updateOrInsert(['teacher_id' => $teacher->id, 'date' => $date], [$column => $time]);

        return DB::table('scan_photos')->insertGetId([
            'teacher_id' => $teacher->id,
            'attendance_date' => $date,
            'scanned_at' => "{$date} {$time}",
            'outcome' => 'accepted',
            'scan_kind' => 'IN',
            'path' => $withPhoto ? 'scan-photos/2026/10/05/'.uniqid().'.jpg' : null,
        ]);
    }

    public function test_the_super_admin_sees_each_scans_photo_in_the_daily_report(): void
    {
        $ana = $this->person();
        $withPhoto = $this->scan($ana, '2026-10-05', 'am_arrival', '07:28:00');
        $this->scan($ana, '2026-10-05', 'pm_departure', '15:05:00', withPhoto: false);

        $response = $this->actingAs($this->account('superadmin'))->get(route('superadmin.reports', ['date' => '2026-10-05']));

        $response->assertSeeInOrder([$ana->fullname, '7:28 AM', '3:05 PM'])
            ->assertSee(route('photos.scan', $withPhoto))
            ->assertSee('No photo')
            ->assertSee('id="scanPhotoModal"', false);
    }

    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_admin_and_principal_never_get_the_photo_strip(string $role): void
    {
        $ana = $this->person();
        $this->scan($ana, '2026-10-05', 'am_arrival', '07:28:00');
        $this->actingAs($this->account($role));

        $allPersonnel = $this->get(route("{$role}.reports", ['date' => '2026-10-05']));
        $onePerson = $this->get(route("{$role}.reports", [
            'teacher_id' => $ana->id, 'date' => '2026-10-05', 'week' => '2026-W41', 'monthPicker' => '2026-10',
        ]));

        foreach ([$allPersonnel, $onePerson] as $response) {
            $response->assertSee('7:28 AM')
                ->assertDontSee('scan-photos')
                ->assertDontSee('photo-strip', false)
                ->assertDontSee('scanPhotoModal', false);
        }
    }

    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_admin_and_principal_cannot_load_a_scan_photo_url(string $role): void
    {
        $photoId = $this->scan($this->person(), '2026-10-05', 'am_arrival', '07:28:00');

        $response = $this->actingAs($this->account($role))->get(route('photos.scan', $photoId));

        $response->assertRedirect(route("{$role}.dashboard"));
    }

    public function test_one_persons_week_shows_their_profile_and_a_photo_strip_per_day(): void
    {
        $ana = $this->person(['id_number' => '2026-0042', 'department' => 'Teacher III']);
        $ben = $this->person();
        $photoId = $this->scan($ana, '2026-10-07', 'am_arrival', '06:55:00');
        $this->scan($ben, '2026-10-07', 'am_arrival', '06:58:00');

        $response = $this->actingAs($this->account('superadmin'))
            ->get(route('superadmin.reports', ['tab' => 'weekly', 'week' => '2026-W41', 'teacher_id' => $ana->id]));

        $response->assertSeeInOrder(['2026-0042', 'Teacher III', 'Teaching Staff'])
            ->assertSee('Oct 5 – Oct 10, 2026')
            ->assertSee(route('photos.scan', $photoId))
            ->assertDontSee('6:58 AM')
            ->assertViewHas('personWeek', fn (array $days) => array_column($days, 'date') === [
                '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10',
            ]);
    }

    public function test_the_daily_report_shows_only_the_selected_dates_scans(): void
    {
        $ana = $this->person();
        $this->scan($ana, '2026-10-05', 'am_arrival', '07:28:00');
        $this->scan($ana, '2026-10-06', 'am_arrival', '06:41:00');

        $response = $this->actingAs($this->account('principal'))->get(route('principal.reports', ['date' => '2026-10-06']));

        $dailyTab = Str::between($response->getContent(), 'id="dailyTab"', 'id="weeklyTab"');
        $response->assertSee('Tuesday, October 6, 2026')
            ->assertViewHas('daily', fn (array $rows) => array_column($rows, 'am_arrival') === ['06:41:00']);
        $this->assertStringContainsString('6:41 AM', $dailyTab);
        $this->assertStringNotContainsString('7:28 AM', $dailyTab);
    }

    public function test_today_lists_not_yet_recorded_personnel_as_a_count_but_past_days_still_list_them(): void
    {
        $everyDay = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $scanned = $this->teacher(['fullname' => 'Ana Scanned', 'created_at' => '2026-01-01 00:00:00'], days: $everyDay);
        $this->teacher(['fullname' => 'Ben Waiting', 'created_at' => '2026-01-01 00:00:00'], days: $everyDay);
        DB::table('attendance')->insert(['teacher_id' => $scanned->id, 'date' => date('Y-m-d'), 'am_arrival' => '06:50:00']);
        $this->actingAs($this->account('admin'));

        $today = $this->get(route('admin.reports'));
        $yesterday = $this->get(route('admin.reports', ['date' => date('Y-m-d', strtotime('-1 day'))]));

        $today->assertViewHas('daily', fn (array $rows) => array_column($rows, 'fullname') === ['Ana Scanned'])
            ->assertSee('1 personnel not yet recorded today.');
        $yesterday->assertViewHas('daily', fn (array $rows) => array_column($rows, 'status', 'fullname') === ['Ana Scanned' => 'Pending Review', 'Ben Waiting' => 'Pending Review'])
            ->assertDontSee('not yet recorded today');
    }

    /**
     * September 2026 (22 weekdays, before the 2026-09-27 rules): Ana is on
     * time, late, absent, on leave, then Pending Review; Ben is on time once.
     *
     * @return array{0: Teacher, 1: Teacher}
     */
    private function septemberRecords(): array
    {
        $ana = $this->person(['fullname' => 'Ana Cruz']);
        $ben = $this->person(['fullname' => 'Ben Reyes']);
        $this->scan($ana, '2026-09-01', 'am_arrival', '06:55:00');
        $this->scan($ben, '2026-09-01', 'am_arrival', '07:10:00');
        $this->scan($ana, '2026-09-02', 'am_arrival', '08:30:00');
        DB::table('confirmed_absences')->insert(['teacher_id' => $ana->id, 'absence_date' => '2026-09-03']);
        DB::table('teacher_leaves')->insert(['teacher_id' => $ana->id, 'leave_type' => 'Sick Leave', 'leave_from' => '2026-09-04', 'leave_until' => '2026-09-04']);

        return [$ana, $ben];
    }

    public function test_the_monthly_calendar_adds_up_to_the_monthly_status_lists_and_summary(): void
    {
        $this->septemberRecords();

        $response = $this->actingAs($this->account('admin'))->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-09']));

        $days = $response->viewData('calendar')['days'];
        $calendarTotals = [];
        foreach ($days as $day) {
            foreach ($day['counts'] as $status => $count) {
                $calendarTotals[$status] = ($calendarTotals[$status] ?? 0) + $count;
            }
        }
        ksort($calendarTotals);
        $this->assertSame(['Present' => 2], $days['2026-09-01']['counts']);
        $this->assertSame(['Late' => 1, 'Pending Review' => 1], $days['2026-09-02']['counts']);
        $this->assertSame(['Absent' => 1, 'Late' => 1, 'On Leave' => 1, 'Pending Review' => 39, 'Present' => 2], $calendarTotals);
        $response->assertViewHas('monthly', fn (array $monthly) => $monthly['lists'] === [
            'Present' => ['Ana Cruz (1)', 'Ben Reyes (1)'],
            'Late' => ['Ana Cruz (1)'],
            'Pending Review' => ['Ana Cruz (18)', 'Ben Reyes (21)'],
            'Absent' => ['Ana Cruz (1)'],
            'On Leave' => ['Ana Cruz (1)'],
        ]);
        $this->assertSame(
            ['Ana Cruz' => [1, 1, 1, 1], 'Ben Reyes' => [1, 0, 0, 0]],
            collect(MonthlySummary::build('2026-09-01', '2026-09-30'))
                ->mapWithKeys(fn (array $row) => [$row['fullname'] => [$row['on_time'], $row['late_count'], $row['absent_count'], $row['on_leave_count']]])
                ->all()
        );
    }

    public function test_a_calendar_day_lists_its_names_by_status_with_the_time_in_and_labels_days_off(): void
    {
        $this->septemberRecords();
        DB::table('school_events')->insert(['event_name' => 'Founding Anniversary', 'event_type' => 'Holiday', 'date_from' => '2026-09-11', 'date_to' => '2026-09-11']);

        $response = $this->actingAs($this->account('principal'))->get(route('principal.reports', ['tab' => 'monthly', 'monthPicker' => '2026-09']));

        $days = $response->viewData('calendar')['days'];
        $this->assertSame([
            'Late' => [['name' => 'Ana Cruz', 'time' => '8:30 AM', 'detail' => '']],
            'Pending Review' => [['name' => 'Ben Reyes', 'time' => '', 'detail' => '']],
        ], $days['2026-09-02']['groups']);
        $this->assertSame([['name' => 'Ana Cruz', 'time' => '', 'detail' => 'Sick Leave']], $days['2026-09-04']['groups']['On Leave']);
        $this->assertSame(['Holiday', []], [$days['2026-09-11']['label'], $days['2026-09-11']['counts']]);
        $this->assertSame('No work', $days['2026-09-05']['label']);
        $this->assertArrayNotHasKey('2026-09-06', $days);
        $response->assertSee('data-date="2026-09-02"', false);
    }

    public function test_calendar_days_after_today_cannot_be_opened(): void
    {
        $this->person();

        $response = $this->actingAs($this->account('admin'))
            ->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => date('Y-m', strtotime('first day of next month'))]));

        $response->assertSee('cal-cell is-future', false)
            ->assertDontSee('data-date=', false);
    }

    public function test_with_the_personnel_filter_each_calendar_day_has_that_persons_status_and_scans(): void
    {
        [$ana] = $this->septemberRecords();

        $response = $this->actingAs($this->account('admin'))
            ->get(route('admin.reports', ['tab' => 'monthly', 'monthPicker' => '2026-09', 'teacher_id' => $ana->id]));

        $days = $response->viewData('calendar')['days'];
        $this->assertSame('Present', $days['2026-09-01']['status']);
        $this->assertSame(
            ['status' => 'Late', 'detail' => '', 'scans' => ['am_arrival' => '8:30 AM', 'am_departure' => '', 'pm_arrival' => '', 'pm_departure' => '']],
            array_intersect_key($days['2026-09-02'], array_flip(['status', 'detail', 'scans']))
        );
        $this->assertSame(['On Leave', 'Sick Leave'], [$days['2026-09-04']['status'], $days['2026-09-04']['detail']]);
    }

    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_admin_and_principal_get_no_photo_urls_from_the_monthly_calendar(string $role): void
    {
        $ana = $this->person();
        $photoId = $this->scan($ana, '2026-09-01', 'am_arrival', '06:55:00');
        $this->actingAs($this->account($role));

        $allPersonnel = $this->get(route("{$role}.reports", ['tab' => 'monthly', 'monthPicker' => '2026-09']));
        $onePerson = $this->get(route("{$role}.reports", ['tab' => 'monthly', 'monthPicker' => '2026-09', 'teacher_id' => $ana->id]));

        foreach ([$allPersonnel, $onePerson] as $response) {
            $response->assertSee('data-date="2026-09-01"', false)
                ->assertDontSee(route('photos.scan', $photoId))
                ->assertDontSee('scan-photos');
        }
    }

    public function test_a_future_or_malformed_date_and_an_archived_person_fall_back_to_today_and_all_personnel(): void
    {
        $archived = $this->person(['is_deleted' => 1]);
        $this->actingAs($this->account('admin'));

        $future = $this->get(route('admin.reports', ['date' => '2099-01-01', 'teacher_id' => $archived->id]));
        $malformed = $this->get(route('admin.reports', ['date' => '2026-02-31']));

        $future->assertViewHas('date', date('Y-m-d'))->assertViewHas('person', null);
        $malformed->assertViewHas('date', date('Y-m-d'));
    }
}
