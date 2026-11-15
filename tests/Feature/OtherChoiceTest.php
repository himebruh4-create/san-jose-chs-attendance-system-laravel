<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Every dropdown with an "Other" choice shows a text box for it (hidden
 * until Other is chosen; app-shell.js data-other-input). The server
 * requires the text with Other, saves it with the record, and pages
 * escape it when they show it.
 */
class OtherChoiceTest extends TestCase
{
    private ?Account $superAdmin = null;

    private function superAdmin(): Account
    {
        return $this->superAdmin ??= $this->account('superadmin');
    }

    #[TestWith(['superadmin.adjustments', 'adjustmentType', 'adjustmentTypeOther'])]
    #[TestWith(['superadmin.personnel', 'leaveTypeSelect', 'leaveTypeOther'])]
    #[TestWith(['superadmin.personnel', 'holidayTypeInput', 'holidayTypeOtherInput'])]
    #[TestWith(['superadmin.personnel', 'addTeacherDeptSelect', 'addTeacherDeptOther'])]
    #[TestWith(['superadmin.personnel', 'modalTeacherDeptSelect', 'modalTeacherDeptOther'])]
    public function test_each_other_choice_has_a_text_box_that_starts_hidden(string $route, string $selectId, string $inputId): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route($route))->getContent();

        $this->assertMatchesRegularExpression('#<select[^>]*id="'.$selectId.'"[^>]*data-other-input="\#'.$inputId.'"#s', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*id="'.$inputId.'"[^>]*\bhidden>#s', $html);
    }

    public function test_an_other_attendance_issue_needs_its_text_and_saves_it(): void
    {
        $teacher = $this->teacher();
        $this->actingAs($this->superAdmin());
        $post = fn (array $extra) => $this->postJson('/data/adjustments', $extra + [
            'teacher_id' => $teacher->id, 'adjustment_date' => '2026-10-05', 'adjustment_type' => 'Other', 'remarks' => 'Kiosk down',
        ]);

        $post(['adjustment_type_other' => '  '])->assertExactJson(['success' => false, 'message' => 'Please type the attendance issue for Other.']);
        $this->assertDatabaseCount('attendance_adjustments', 0);

        $post(['adjustment_type_other' => 'Power outage'])->assertJson(['success' => true]);
        $this->assertDatabaseHas('attendance_adjustments', ['teacher_id' => $teacher->id, 'adjustment_type' => 'Other: Power outage', 'reason' => 'Other: Power outage']);
        $this->getJson('/data/adjustments')->assertJsonPath('adjustments.0.adjustment_type', 'Other: Power outage');
    }

    public function test_text_typed_with_another_choice_is_not_saved(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($this->superAdmin())->postJson('/data/adjustments', [
            'teacher_id' => $teacher->id, 'adjustment_date' => '2026-10-05', 'adjustment_type' => 'Forgot to Scan',
            'adjustment_type_other' => 'left over', 'am_arrival' => '07:00', 'remarks' => 'Forgot',
        ])->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendance_adjustments', ['teacher_id' => $teacher->id, 'adjustment_type' => 'Forgot to Scan']);
    }

    public function test_an_other_leave_type_needs_its_text_and_saves_it(): void
    {
        $teacher = $this->teacher();
        $this->actingAs($this->superAdmin());
        $post = fn (array $extra) => $this->post('/superadmin/personnel/leave', $extra + [
            'leave_teacher_id' => $teacher->id, 'leave_type' => 'Other', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-05',
        ]);

        $post([])->assertSessionHas('message', 'Please type the leave type for Other.');
        $this->assertDatabaseCount('teacher_leaves', 0);

        $post(['leave_type_other' => 'Bereavement Leave'])->assertSessionHas('message_type', 'success');
        $this->assertDatabaseHas('teacher_leaves', ['teacher_id' => $teacher->id, 'leave_type' => 'Other: Bereavement Leave']);
    }

    public function test_an_other_schedule_change_type_needs_its_text_and_saves_it(): void
    {
        $this->actingAs($this->superAdmin());
        $post = fn (array $extra) => $this->postJson('/data/school-events', $extra + [
            'event_name' => 'District Meet', 'event_type' => 'Other', 'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'included_in_total_hours' => '0',
        ]);

        $post([])->assertExactJson(['success' => false, 'message' => 'Please type the schedule change type for Other.']);
        $this->assertDatabaseCount('school_events', 0);

        $post(['event_type_other' => 'Sports Fest'])->assertJson(['success' => true]);
        $this->assertDatabaseHas('school_events', ['event_name' => 'District Meet', 'event_type' => 'Other: Sports Fest']);
    }

    public function test_a_position_named_other_saves_the_typed_position(): void
    {
        DB::table('department_options')->insert(['option_type' => 'Position', 'personnel_type' => 'Teaching', 'option_name' => 'Other']);
        $this->actingAs($this->superAdmin());
        $post = fn (array $extra) => $this->post('/superadmin/personnel/save', $extra + [
            'name' => 'Lia Santos', 'department' => 'Other', 'academic_status' => 'Academic', 'employment_type' => 'Regular',
            'id_number' => '2026-7777', 'barcode' => '777777',
        ]);

        $post([])->assertSessionHasErrors();
        $this->assertDatabaseMissing('teachers', ['fullname' => 'Lia Santos']);

        $post(['department_other' => 'School Librarian']);
        $this->assertDatabaseHas('teachers', ['fullname' => 'Lia Santos', 'department' => 'School Librarian']);
    }

    public function test_an_other_dtr_remark_needs_its_text(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($this->account('admin'))->postJson('/data/dtr-remarks', [
            'teacher_id' => $teacher->id, 'month_start' => '2026-10-01', 'month_end' => '2026-10-31',
            'remarks' => json_encode([['remark_type' => 'Other', 'date' => '2026-10-07', 'remark_text' => '']]),
        ])->assertExactJson(['success' => false, 'message' => 'Please type the remark for Other.']);

        $this->assertDatabaseCount('dtr_remarks', 0);
    }

    public function test_typed_other_text_is_escaped_where_it_is_shown(): void
    {
        $teacher = $this->teacher(['fullname' => 'Ana Cruz']);
        $today = date('Y-m-d');
        $this->actingAs($this->superAdmin());

        $this->post('/superadmin/personnel/leave', [
            'leave_teacher_id' => $teacher->id, 'leave_type' => 'Other', 'leave_type_other' => '<script>alert(1)</script>',
            'leave_from' => $today, 'leave_until' => $today,
        ])->assertSessionHas('message_type', 'success');
        $this->postJson('/data/school-events', [
            'event_name' => 'Meet', 'event_type' => 'Other', 'event_type_other' => '<b>Fest</b>',
            'date_from' => $today, 'date_to' => $today, 'included_in_total_hours' => '0',
        ])->assertJson(['success' => true]);

        $this->get(route('superadmin.personnel'))
            ->assertSee('Other: &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->flushSession();
        $this->actingAs($this->account('admin'))->get(route('admin.dashboard'))
            ->assertSee('Other: &lt;b&gt;Fest&lt;/b&gt;', false)
            ->assertDontSee('<b>Fest</b>', false);
    }
}
