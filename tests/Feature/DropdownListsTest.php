<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Settings -> Dropdown Lists: Positions and Leave Types in one tab, both
 * managed the same way (Add, and Deactivate to the Recycle Bin). A leave
 * type a leave record uses cannot be removed.
 */
class DropdownListsTest extends TestCase
{
    private function addOption(string $type, string $name, string $personnelType = 'Teaching'): TestResponse
    {
        return $this->postJson('/data/department-options', [
            'option_type' => $type, 'option_name' => $name, 'personnel_type' => $personnelType,
        ]);
    }

    private function leaveTypeId(string $name, string $personnelType): int
    {
        return (int) DB::table('department_options')
            ->where(['option_type' => 'Leave Type', 'option_name' => $name, 'personnel_type' => $personnelType])
            ->value('id');
    }

    private function addLeave(int $teacherId, string $type)
    {
        return $this->post('/superadmin/personnel/leave', [
            'leave_teacher_id' => $teacherId, 'leave_type' => $type, 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-05',
        ]);
    }

    public function test_settings_shows_positions_and_leave_types_in_one_dropdown_lists_tab(): void
    {
        $this->actingAs($this->account('superadmin'))->get(route('superadmin.settings', ['tab' => 'lists']))
            ->assertOk()
            ->assertSee('data-tab="lists"', false)
            ->assertDontSee('data-tab="positions"', false)
            ->assertSeeInOrder(['data-settings-panel="lists"', '<h2>Positions</h2>', '<h2>Leave Types</h2>'], false);
    }

    public function test_an_old_positions_tab_link_opens_dropdown_lists(): void
    {
        $html = $this->actingAs($this->account('superadmin'))->get(route('superadmin.settings', ['tab' => 'positions']))->getContent();

        $this->assertMatchesRegularExpression('/data-tab="lists"\s+aria-selected="true"/', $html);
    }

    public function test_a_new_leave_type_is_offered_and_accepted_for_its_personnel_type_only(): void
    {
        $this->actingAs($this->account('superadmin'));
        $teacher = $this->teacher(['academic_status' => 'Academic']);
        $staff = $this->teacher(['academic_status' => 'Non-Academic']);

        $this->addOption('Leave Type', 'Study Leave', 'Teaching')
            ->assertJson(['success' => true, 'message' => 'Leave type added successfully.']);

        $this->getJson('/data/department-options?type=Leave+Type&personnel_type=Teaching')
            ->assertJsonPath('options.6.option_name', 'Study Leave');
        $this->addLeave($teacher->id, 'Study Leave')->assertSessionHas('message', 'Personnel leave added successfully.');
        $this->addLeave($staff->id, 'Study Leave')
            ->assertSessionHas('message', 'Study Leave is not available for non-teaching staff. Please choose another leave type.');
        $this->assertSame([$teacher->id], DB::table('teacher_leaves')->pluck('teacher_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_leave_type_can_be_on_both_lists_but_not_twice_on_one(): void
    {
        $this->actingAs($this->account('superadmin'));

        $this->addOption('Leave Type', 'Study Leave', 'Teaching')->assertJson(['success' => true]);
        $this->addOption('Leave Type', 'Study Leave', 'Non-Teaching')->assertJson(['success' => true]);
        $this->addOption('Leave Type', 'Study Leave', 'Teaching')
            ->assertJson(['success' => false, 'message' => 'This leave type already exists.']);

        $this->assertSame(2, DB::table('department_options')->where(['option_type' => 'Leave Type', 'option_name' => 'Study Leave'])->count());
    }

    public function test_a_leave_type_needs_a_personnel_type_and_cannot_be_named_other(): void
    {
        $this->actingAs($this->account('superadmin'));
        $before = DB::table('department_options')->count();

        $this->addOption('Leave Type', 'Study Leave', '')
            ->assertJson(['success' => false, 'message' => 'Please select a Personnel Type for this leave type.']);
        $this->addOption('Leave Type', 'other')
            ->assertJson(['success' => false, 'message' => '"Other" is always offered in the Add Leave form. Please enter a different name.']);

        $this->assertSame($before, DB::table('department_options')->count());
    }

    public function test_a_leave_type_used_by_a_leave_record_cannot_be_deactivated(): void
    {
        $this->actingAs($this->account('superadmin'));
        $staff = $this->teacher(['academic_status' => 'Non-Academic']);
        DB::table('teacher_leaves')->insert(['teacher_id' => $staff->id, 'leave_type' => 'Forced Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-05']);
        $id = $this->leaveTypeId('Forced Leave', 'Non-Teaching');

        $this->postJson('/data/department-options/deactivate', ['id' => $id])
            ->assertJson(['success' => false, 'message' => 'Forced Leave is used by a leave record, so it cannot be removed.']);

        $this->assertSame('Active', DB::table('department_options')->where('id', $id)->value('status'));
    }

    public function test_an_unused_leave_type_moves_to_the_recycle_bin_and_leaves_the_add_leave_form_until_restored(): void
    {
        $this->actingAs($this->account('superadmin'));
        $id = $this->leaveTypeId('Forced Leave', 'Non-Teaching');

        $this->postJson('/data/department-options/deactivate', ['id' => $id])->assertJson(['success' => true]);

        $this->getJson('/data/recycle-bin?type=leave_type')
            ->assertJsonPath('rows.0.title', 'Forced Leave')
            ->assertJsonPath('rows.0.subtitle', 'Leave Type · Non-Teaching');
        $this->get('/superadmin/personnel')->assertDontSee('Forced Leave');

        $this->postJson('/data/recycle-bin', ['type' => 'leave_type', 'action' => 'restore', 'id' => $id])->assertJson(['success' => true]);
        $this->assertSame('Active', DB::table('department_options')->where('id', $id)->value('status'));
    }

    public function test_the_recycle_bin_never_deletes_a_leave_type_a_leave_record_uses(): void
    {
        $this->actingAs($this->account('superadmin'));
        $staff = $this->teacher(['academic_status' => 'Non-Academic']);
        $usedId = $this->leaveTypeId('Forced Leave', 'Non-Teaching');
        $unusedId = $this->leaveTypeId('Special Privilege Leave', 'Non-Teaching');
        DB::table('department_options')->whereIn('id', [$usedId, $unusedId])->update(['status' => 'Inactive']);
        // The leave was recorded before its type was deactivated.
        DB::table('teacher_leaves')->insert(['teacher_id' => $staff->id, 'leave_type' => 'Forced Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-05']);

        $this->postJson('/data/recycle-bin', ['type' => 'leave_type', 'action' => 'permanent_delete', 'id' => $usedId])
            ->assertJson(['success' => false, 'message' => 'Forced Leave is used by a leave record, so it cannot be removed.']);
        $this->postJson('/data/recycle-bin', ['type' => 'leave_type', 'action' => 'delete_all'])
            ->assertJson(['success' => true, 'affected' => 1]);

        $this->assertDatabaseHas('department_options', ['id' => $usedId]);
        $this->assertDatabaseMissing('department_options', ['id' => $unusedId]);
    }

    public function test_positions_still_add_and_deactivate_as_before(): void
    {
        $this->actingAs($this->account('superadmin'));

        $id = $this->addOption('Position', 'Teacher IV')
            ->assertJson(['success' => true, 'message' => 'Position added successfully.'])
            ->json('id');
        $this->addOption('Position', 'Teacher IV', 'Non-Teaching')
            ->assertJson(['success' => false, 'message' => 'This position already exists.']);
        $this->postJson('/data/department-options/deactivate', ['id' => $id])->assertJson(['success' => true]);

        $this->assertSame('Inactive', DB::table('department_options')->where('id', $id)->value('status'));
    }

    public function test_only_the_super_admin_can_add_a_leave_type(): void
    {
        $this->actingAs($this->account('admin'));

        $this->addOption('Leave Type', 'Study Leave')->assertForbidden();

        $this->assertDatabaseMissing('department_options', ['option_name' => 'Study Leave']);
    }
}
