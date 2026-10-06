<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Super Admin write flows: personnel, leave, adjustments, absences, school
 * schedule changes, accounts and the recycle bin.
 */
class ManagementTest extends TestCase
{
    private function superAdmin()
    {
        return $this->actingAs($this->account('superadmin'));
    }

    public function test_adding_personnel_saves_schedule_photo_id_number_and_barcode(): void
    {
        Storage::fake('local');

        $this->superAdmin()->post('/superadmin/personnel/save', [
            'name' => 'Maria  Dela Cruz II',
            'academic_status' => 'Academic',
            'department' => 'Teacher II',
            'employment_type' => 'Regular',
            'schedule' => [
                'monday' => ['enabled' => '1', 'time_in' => '07:00', 'time_out' => '15:00'],
                'saturday' => ['enabled' => '1', 'time_in' => '08:00', 'time_out' => '12:00'],
                'tuesday' => ['time_in' => '07:00', 'time_out' => '15:00'],   // not enabled
            ],
            'photo' => UploadedFile::fake()->image('me.png', 1200, 900),
        ])->assertRedirect()->assertSessionHas('message', 'Personnel added successfully.');

        $teacher = DB::table('teachers')->where('fullname', 'Maria Dela Cruz II')->first();

        $this->assertNotNull($teacher, 'Name keeps its casing (no more "Ii") and spaces are collapsed.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $teacher->id_number);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $teacher->barcode);
        $this->assertSame(['Monday', 'Saturday'], DB::table('teacher_schedules')->where('teacher_id', $teacher->id)->orderBy('id')->pluck('day')->all());

        Storage::disk('local')->assertExists('personnel-photos/'.$teacher->photo);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get('personnel-photos/'.$teacher->photo));
        $this->assertSame([800, 600], [$w, $h], 'Photos are resized to fit 800x800.');
    }

    public function test_a_duplicate_barcode_is_refused_with_a_clear_message(): void
    {
        $existing = $this->teacher();

        $this->superAdmin()->post('/superadmin/personnel/save', [
            'name' => 'Someone Else', 'academic_status' => 'Academic', 'department' => 'Teacher I',
            'employment_type' => 'Regular', 'barcode' => $existing->barcode,
        ])->assertSessionHasErrors();

        $this->assertSame(1, DB::table('teachers')->count());
    }

    public function test_archiving_and_regenerating_a_barcode(): void
    {
        $t = $this->teacher();
        $old = $t->barcode;

        $this->superAdmin()->post('/superadmin/personnel/regenerate-barcode', ['id' => $t->id])->assertSessionHas('message_type', 'success');
        $this->assertNotSame($old, $t->fresh()->barcode);

        $this->post('/superadmin/personnel/archive', ['id' => $t->id]);
        $this->assertTrue($t->fresh()->is_deleted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'personnel.archived', 'subject_id' => $t->id]);
    }

    public function test_overlapping_leave_is_refused(): void
    {
        $t = $this->teacher();

        $this->superAdmin()->post('/superadmin/personnel/leave', [
            'leave_teacher_id' => $t->id, 'leave_type' => 'Sick Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-07',
        ])->assertSessionHas('message_type', 'success');

        $this->post('/superadmin/personnel/leave', [
            'leave_teacher_id' => $t->id, 'leave_type' => 'Vacation Leave', 'leave_from' => '2026-10-07', 'leave_until' => '2026-10-09',
        ])->assertSessionHas('message', 'This personnel already has an approved leave that overlaps these dates.');

        $this->assertSame(1, DB::table('teacher_leaves')->count());
    }

    public function test_an_adjustment_records_who_approved_it_and_can_be_archived(): void
    {
        $t = $this->teacher();
        $sa = $this->account('superadmin', ['email' => 'boss@test.local']);

        $id = $this->actingAs($sa)->postJson('/data/adjustments', [
            'teacher_id' => $t->id, 'adjustment_date' => '2026-10-05', 'adjustment_type' => 'Forgot to Scan',
            'am_arrival' => '07:00', 'pm_departure' => '15:00', 'remarks' => 'Forgot to scan',
        ])->assertJson(['success' => true])->json('adjustment_id');

        $this->assertDatabaseHas('attendance_adjustments', ['id' => $id, 'approved_by' => 'boss@test.local', 'am_arrival' => '07:00:00']);

        $this->postJson('/data/adjustments', [
            'teacher_id' => $t->id, 'adjustment_date' => '2026-10-05', 'adjustment_type' => 'Other', 'remarks' => 'again',
        ])->assertJson(['success' => false, 'message' => 'An attendance adjustment already exists for this teacher on this date.']);

        $this->postJson('/data/adjustments', [
            'teacher_id' => $t->id, 'adjustment_date' => '2026-10-06', 'adjustment_type' => 'Other', 'remarks' => 'x', 'am_arrival' => '7am',
        ])->assertJson(['success' => false, 'message' => 'Please enter valid times.']);

        $this->postJson('/data/adjustments/delete', ['id' => $id])->assertJson(['success' => true]);
        $this->assertDatabaseHas('attendance_adjustments', ['id' => $id, 'is_deleted' => 1, 'deleted_by' => 'boss@test.local']);
    }

    public function test_admin_can_confirm_an_absence_and_save_dtr_remarks(): void
    {
        $t = $this->teacher();
        $admin = $this->account('admin');

        $this->actingAs($admin)->postJson('/data/confirm-absent', ['teacher_id' => $t->id, 'date' => '2026-10-05'])
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('confirmed_absences', ['teacher_id' => $t->id, 'absence_date' => '2026-10-05', 'confirmed_by' => $admin->email]);

        $this->postJson('/data/dtr-remarks', [
            'teacher_id' => $t->id, 'month_start' => '2026-10-01', 'month_end' => '2026-10-31',
            'remarks' => json_encode([
                ['remark_type' => 'Official Business', 'date' => '2026-10-07', 'duration' => 'Half Day', 'half_day_session' => 'AM', 'included_in_total_hours' => '1'],
                ['remark_type' => 'On Leave', 'date_from' => '2026-10-12', 'date_to' => '2026-10-14', 'status' => 'Active'],
                ['remark_type' => 'Absent', 'date' => '2026-11-02'],   // outside the month: skipped
            ]),
        ])->assertJson(['success' => true]);

        $this->assertSame(2, DB::table('dtr_remarks')->where('teacher_id', $t->id)->count());
        $this->assertDatabaseHas('dtr_remarks', ['date' => '2026-10-07', 'half_day_session' => 'AM', 'included_in_total_hours' => 1]);
    }

    public function test_school_schedule_change_with_personnel_scheduled_to_work(): void
    {
        $a = $this->teacher();
        $b = $this->teacher();

        $id = $this->superAdmin()->postJson('/data/school-events', [
            'event_name' => 'Division Meet II', 'event_type' => 'Other', 'date_from' => '2026-10-20', 'date_to' => '2026-10-20',
            'duration' => 'Half Day', 'start_time' => '13:00', 'end_time' => '17:00', 'included_in_total_hours' => '1',
            'personnel_ids' => [$a->id],
        ])->assertJson(['success' => true])->json('id');

        $this->assertDatabaseHas('school_events', ['id' => $id, 'event_name' => 'Division Meet II', 'duration' => 'Half Day']);
        $this->assertSame([$a->id], DB::table('school_event_personnel')->where('school_event_id', $id)->pluck('teacher_id')->all());

        $this->postJson('/data/school-events/update', [
            'id' => $id, 'event_name' => 'Division Meet II', 'date_from' => '2026-10-20', 'date_to' => '2026-10-21',
            'included_in_total_hours' => '0', 'personnel_ids' => [$b->id],
        ])->assertJson(['success' => true]);

        $this->assertSame([$b->id], DB::table('school_event_personnel')->where('school_event_id', $id)->pluck('teacher_id')->all());

        $this->getJson('/data/school-events')->assertJsonPath('events.0.worker_ids', [$b->id]);

        $this->postJson('/data/school-events/deactivate', ['id' => $id])->assertJson(['success' => true]);
        $this->getJson('/data/school-events')->assertJsonCount(0, 'events');
    }

    public function test_account_management_never_touches_a_super_admin(): void
    {
        $sa = $this->account('superadmin');
        $other = $this->account('superadmin');

        $this->actingAs($sa)->post('/superadmin/settings/accounts', [
            'full_name' => 'New Principal', 'email' => 'NewP@Test.local', 'password' => 'Welcome#2026',
            'confirm_password' => 'Welcome#2026', 'role' => 'superadmin',
        ])->assertSessionHas('message', 'Invalid role selected.');

        $this->post('/superadmin/settings/accounts', [
            'full_name' => 'New Principal', 'email' => 'NewP@Test.local', 'password' => 'Welcome#2026',
            'confirm_password' => 'Welcome#2026', 'role' => 'principal',
        ])->assertSessionHas('message', 'Account created successfully.');
        $this->assertDatabaseHas('accounts', ['email' => 'newp@test.local', 'role' => 'principal']);

        $this->post('/superadmin/settings/accounts/archive', ['account_id' => $other->id])->assertSessionHas('message_type', 'error');
        $this->post('/superadmin/settings/accounts/reset-password', [
            'account_id' => $other->id, 'new_password' => 'Hijack#2026', 'confirm_password' => 'Hijack#2026',
        ])->assertSessionHas('message', 'Invalid account.');
        $this->assertFalse($other->fresh()->is_deleted);
    }

    public function test_permanently_deleting_personnel_keeps_their_attendance_in_the_archive(): void
    {
        $t = $this->teacher(['fullname' => 'Gone Person']);
        DB::table('attendance')->insert(['teacher_id' => $t->id, 'date' => '2026-10-05', 'am_arrival' => '07:00:00', 'attendance_status' => 'AM Active']);
        $t->forceFill(['is_deleted' => true, 'deleted_at' => now()])->save();

        $this->superAdmin()->postJson('/data/recycle-bin', ['type' => 'teacher', 'action' => 'permanent_delete', 'id' => $t->id])
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('teachers', ['id' => $t->id]);
        $this->assertDatabaseCount('attendance', 0);
        $this->assertDatabaseHas('archived_attendance', ['original_teacher_id' => $t->id, 'fullname' => 'Gone Person', 'date' => '2026-10-05', 'am_arrival' => '07:00:00']);
    }

    public function test_recycle_bin_restores_an_archived_account(): void
    {
        $admin = $this->account('admin', ['email' => 'old@test.local']);
        $admin->forceFill(['is_deleted' => true, 'deleted_at' => now()])->save();

        $this->superAdmin()->getJson('/data/recycle-bin?type=account')->assertJsonPath('rows.0.title', $admin->full_name);
        $this->postJson('/data/recycle-bin', ['type' => 'account', 'action' => 'restore', 'id' => $admin->id])->assertJson(['success' => true]);
        $this->assertFalse($admin->fresh()->is_deleted);
    }
}
