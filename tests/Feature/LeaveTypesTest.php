<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Leave types by personnel type (App\Domain\LeaveTypes): Personnel -> Add
 * Leave offers and accepts only the types of the selected person's type,
 * and every type shows wherever leave appears.
 */
class LeaveTypesTest extends TestCase
{
    private const TEACHING_TYPES = [
        'Sick Leave', 'Vacation Leave', 'Wellness Leave', 'Emergency Leave', 'Maternity Leave', 'Paternity Leave', 'Other',
    ];

    private const NON_TEACHING_TYPES = [
        'Sick Leave', 'Vacation Leave', 'Forced Leave', 'Special Privilege Leave', 'Wellness Leave',
        'Emergency Leave', 'Maternity Leave', 'Paternity Leave', 'Other',
    ];

    private ?Account $superAdmin = null;

    private function addLeave(Teacher $teacher, string $type, string $from = '2026-10-05', ?string $until = null)
    {
        $this->superAdmin ??= $this->account('superadmin');

        return $this->actingAs($this->superAdmin)->post('/superadmin/personnel/leave', [
            'leave_teacher_id' => $teacher->id, 'leave_type' => $type, 'leave_from' => $from, 'leave_until' => $until ?? $from,
        ]);
    }

    #[TestWith(['Forced Leave'])]
    #[TestWith(['Special Privilege Leave'])]
    public function test_teaching_personnel_cannot_be_given_a_non_teaching_leave_type(string $type): void
    {
        $teacher = $this->teacher(['academic_status' => 'Academic']);

        $this->addLeave($teacher, $type)
            ->assertSessionHas('message_type', 'error')
            ->assertSessionHas('message', "{$type} is not available for teaching staff. Please choose another leave type.");

        $this->assertDatabaseCount('teacher_leaves', 0);
    }

    public function test_a_leave_type_outside_both_lists_is_refused(): void
    {
        $staff = $this->teacher(['academic_status' => 'Non-Academic']);

        $this->addLeave($staff, 'Study Leave')
            ->assertSessionHas('message', 'Study Leave is not available for non-teaching staff. Please choose another leave type.');

        $this->assertDatabaseCount('teacher_leaves', 0);
    }

    public function test_personnel_without_a_type_get_only_the_common_leave_types(): void
    {
        $person = $this->teacher(['academic_status' => null]);

        $this->addLeave($person, 'Wellness Leave')
            ->assertSessionHas('message', 'Wellness Leave is not available for this personnel. Please choose another leave type.');
        $this->addLeave($person, 'Sick Leave')->assertSessionHas('message_type', 'success');
    }

    #[TestWith(['Academic', self::TEACHING_TYPES])]
    #[TestWith(['Non-Academic', self::NON_TEACHING_TYPES])]
    public function test_each_personnel_type_can_be_given_every_one_of_its_leave_types(string $personnelType, array $types): void
    {
        $person = $this->teacher(['academic_status' => $personnelType]);

        foreach ($types as $day => $type) {
            $date = date('Y-m-d', strtotime('2026-10-05 +'.$day.' days'));
            $this->addLeave($person, $type, $date)->assertSessionHas('message', 'Personnel leave added successfully.');
        }

        $this->assertSame($types, DB::table('teacher_leaves')->orderBy('leave_from')->pluck('leave_type')->all());
    }

    public function test_the_add_leave_form_offers_each_personnel_types_leave_types_in_order(): void
    {
        $teacher = $this->teacher(['academic_status' => 'Academic', 'fullname' => 'Ana Cruz']);

        $options = e(json_encode([
            'Academic' => self::TEACHING_TYPES,
            'Non-Academic' => self::NON_TEACHING_TYPES,
            '' => ['Sick Leave', 'Vacation Leave', 'Emergency Leave', 'Maternity Leave', 'Paternity Leave', 'Other'],
        ]));

        $this->actingAs($this->account('superadmin'))->get('/superadmin/personnel')
            ->assertSee('data-leave-types="'.$options.'"', false)
            ->assertSee('<option value="'.$teacher->id.'" data-personnel-type="Academic">', false);
    }

    #[TestWith(['Wellness Leave'])]
    #[TestWith(['Forced Leave'])]
    #[TestWith(['Special Privilege Leave'])]
    public function test_a_new_leave_type_shows_on_the_reports_the_printout_and_the_dtr_data(string $type): void
    {
        $staff = $this->teacher(['academic_status' => 'Non-Academic', 'created_at' => '2026-01-01 00:00:00']);
        $this->addLeave($staff, $type)->assertSessionHas('message_type', 'success');
        $this->flushSession();
        $this->actingAs($this->account('admin'));

        $this->get(route('admin.reports', ['date' => '2026-10-05']))
            ->assertViewHas('daily', fn (array $rows) => $rows[0]['status'] === 'On Leave' && $rows[0]['detail'] === $type);
        $this->get(route('admin.reports', ['teacher_id' => $staff->id, 'tab' => 'monthly', 'monthPicker' => '2026-10']))
            ->assertViewHas('personMonth', fn (array $rows) => collect($rows)->firstWhere('date', '2026-10-05')['detail'] === $type);
        $this->get(route('reports.attendance-print', ['period' => 'daily', 'date' => '2026-10-05']))
            ->assertSee($type);
        $this->getJson('/data/teacher-leaves?teacher_id='.$staff->id)
            ->assertJsonPath('leaves.0.leave_type', $type);
    }

    public function test_a_new_leave_type_shows_on_the_dashboards_personnel_on_leave_today(): void
    {
        $staff = $this->teacher(['academic_status' => 'Non-Academic', 'fullname' => 'Ben Reyes', 'created_at' => '2026-01-01 00:00:00'], days: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
        $this->addLeave($staff, 'Special Privilege Leave', date('Y-m-d'));
        $this->flushSession();

        $this->actingAs($this->account('principal'))->get(route('principal.dashboard'))
            ->assertSeeInOrder(['Ben Reyes', 'Special Privilege Leave']);
    }

    #[TestWith(['Wellness Leave'])]
    #[TestWith(['Sick Leave'])]
    public function test_the_personnel_on_leave_table_shows_the_leave_type_in_the_on_leave_pill(string $type): void
    {
        $teacher = $this->teacher(['academic_status' => 'Academic', 'fullname' => 'Ana Cruz']);
        $this->addLeave($teacher, $type, date('Y-m-d'));

        $this->actingAs($this->superAdmin)->get('/superadmin/personnel')
            ->assertSeeInOrder(['Ana Cruz', '<span class="pill pill-leave pill-leave-detail">On Leave &middot; '.$type.'</span>'], false);
    }

    public function test_an_existing_leave_keeps_its_type_even_when_it_is_no_longer_offered(): void
    {
        $teacher = $this->teacher(['academic_status' => 'Academic', 'created_at' => '2026-01-01 00:00:00']);
        DB::table('teacher_leaves')->insert(['teacher_id' => $teacher->id, 'leave_type' => 'Forced Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-05']);

        $this->actingAs($this->account('admin'))->get(route('admin.reports', ['date' => '2026-10-05']))
            ->assertViewHas('daily', fn (array $rows) => $rows[0]['detail'] === 'Forced Leave');
        $this->assertDatabaseHas('teacher_leaves', ['teacher_id' => $teacher->id, 'leave_type' => 'Forced Leave']);
    }
}
