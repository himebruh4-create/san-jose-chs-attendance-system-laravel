<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Attendance Report month pickers: the one beside the search sets the
 * month of every card; a card's own picker loads only that person's month
 * (/data/dtr-month) and changes nothing else. Generate All DTR reads its
 * data in one request (/data/dtr-bundle).
 */
class DtrMonthPickerTest extends TestCase
{
    private function person(string $name): Teacher
    {
        return $this->teacher(['fullname' => $name, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function arrival(Teacher $teacher, string $date): void
    {
        DB::table('attendance')->insert(['teacher_id' => $teacher->id, 'date' => $date, 'am_arrival' => '06:58:00']);
    }

    public function test_the_month_beside_the_search_sets_every_cards_month(): void
    {
        $ana = $this->person('Ana Cruz');
        $ben = $this->person('Ben Reyes');
        $this->arrival($ana, '2026-09-14');
        $this->arrival($ana, '2026-10-05');
        $this->arrival($ben, '2026-09-15');

        $response = $this->actingAs($this->account('admin'))->get(route('admin.attendance-report', ['monthPicker' => '2026-09']));

        $cards = $response->viewData('teacherReports');
        $this->assertSame(['2026-09-01', '2026-09-01'], array_column($cards, 'monthStart'));
        $this->assertSame([['2026-09-14'], ['2026-09-15']], array_map(fn (array $card) => array_column($card['records'], 'date'), $cards));
        $this->assertSame(2, substr_count($response->getContent(), 'class="monthPicker" value="2026-09"'));
    }

    public function test_a_cards_month_loads_only_that_persons_records_for_that_month(): void
    {
        $ana = $this->person('Ana Cruz');
        $ben = $this->person('Ben Reyes');
        $this->arrival($ana, '2026-09-14');
        $this->arrival($ana, '2026-10-05');
        $this->arrival($ben, '2026-10-06');

        $response = $this->actingAs($this->account('admin'))->getJson('/data/dtr-month?teacher_id='.$ana->id.'&month=2026-10');

        $response->assertJsonPath('teacher.id', $ana->id)
            ->assertJsonPath('teacher.monthStart', '2026-10-01')
            ->assertJsonPath('teacher.print.title', 'DTR - Ana Cruz - 2026-10 - Administrative Officer');
        $this->assertSame(['2026-10-05'], array_column($response->json('teacher.records'), 'date'));
    }

    #[TestWith(['month=2026-13'])]
    #[TestWith(['month=October'])]
    #[TestWith(['month=2026-10', true])]
    public function test_a_cards_month_is_refused_for_a_bad_month_or_an_archived_person(string $query, bool $archived = false): void
    {
        $ana = $this->person('Ana Cruz');
        if ($archived) {
            DB::table('teachers')->where('id', $ana->id)->update(['is_deleted' => 1]);
        }

        $this->actingAs($this->account('superadmin'))
            ->getJson('/data/dtr-month?teacher_id='.$ana->id.'&'.$query)
            ->assertExactJson(['success' => false, 'message' => 'Personnel and a valid month are required.']);
    }

    public function test_the_principal_cannot_read_dtr_data(): void
    {
        $ana = $this->person('Ana Cruz');
        $this->actingAs($this->account('principal'));

        $this->getJson('/data/dtr-month?teacher_id='.$ana->id.'&month=2026-10')->assertForbidden();
        $this->getJson('/data/dtr-bundle?month=2026-10')->assertForbidden();
    }

    public function test_generate_all_dtr_gets_every_persons_month_data_in_one_response(): void
    {
        $ana = $this->person('Ana Cruz');
        $ben = $this->person('Ben Reyes');
        $this->arrival($ana, '2026-10-05');
        DB::table('teacher_leaves')->insert(['teacher_id' => $ben->id, 'leave_type' => 'Sick Leave', 'leave_from' => '2026-10-06', 'leave_until' => '2026-10-06']);
        DB::table('confirmed_absences')->insert(['teacher_id' => $ana->id, 'absence_date' => '2026-10-07']);

        $response = $this->actingAs($this->account('superadmin'))->getJson('/data/dtr-bundle?month=2026-10');

        $response->assertJson(['success' => true, 'month_start' => '2026-10-01', 'month_end' => '2026-10-31'])
            ->assertJsonPath('teachers.0.fullname', 'Ana Cruz')
            ->assertJsonPath('teachers.0.records.0.date', '2026-10-05')
            ->assertJsonPath('teachers.0.absences', ['2026-10-07'])
            ->assertJsonPath('teachers.0.schedule.monday', ['time_in' => '07:00:00', 'time_out' => '15:00:00'])
            ->assertJsonPath('teachers.1.fullname', 'Ben Reyes')
            ->assertJsonPath('teachers.1.leaves.0.leave_type', 'Sick Leave')
            ->assertJsonPath('lunch.lunch_out', '12:15:00');
    }

    public function test_the_attendance_report_page_runs_the_same_number_of_queries_however_many_personnel(): void
    {
        $admin = $this->account('admin');
        $this->person('Ana Cruz');
        $queriesFor = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get(route('admin.attendance-report', ['monthPicker' => '2026-10']))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $queriesFor();   // the first request also registers the session (one device per account)
        $withOne = $queriesFor();
        foreach (range(1, 4) as $n) {
            $this->person("Teacher {$n}");
        }

        $this->assertSame($withOne, $queriesFor());
    }
}
