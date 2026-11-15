<?php

namespace Tests\Feature;

use App\Domain\Attendance\Scanner;
use App\Models\KioskDevice;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Leave added by the Super Admin takes effect at once. A kiosk scan on a
 * leave day is recorded as usual and flagged for review on Pending Review;
 * the leave is never changed by it. Scans are always for the server's date.
 */
class LeaveDayScanTest extends TestCase
{
    private function scan(Teacher $teacher, string $at, array $extra = [])
    {
        $this->travelTo(Carbon::parse($at));

        return $this->withCredentials()
            ->withCookie(KioskDevice::COOKIE, $this->kioskToken())
            ->postJson('/kiosk/scan', $extra + ['barcode' => $teacher->barcode]);
    }

    private function leave(Teacher $teacher, string $from, string $until): int
    {
        return DB::table('teacher_leaves')->insertGetId([
            'teacher_id' => $teacher->id, 'leave_type' => 'Vacation Leave', 'leave_from' => $from, 'leave_until' => $until,
        ]);
    }

    public function test_a_scan_on_a_leave_day_is_recorded_and_flagged_without_changing_the_leave(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher();
        $leaveId = $this->leave($teacher, '2026-10-05', '2026-10-06');

        $this->scan($teacher, '2026-10-05 06:58:00')
            ->assertJson(['success' => true, 'title' => 'AM Arrival', 'leave_conflict' => true])
            ->assertJsonPath('message', fn (string $message) => str_ends_with($message, Scanner::SCAN_ON_LEAVE_DAY));

        $this->assertDatabaseHas('attendance', ['teacher_id' => $teacher->id, 'date' => '2026-10-05', 'am_arrival' => '06:58:00']);
        $this->assertDatabaseHas('attendance_leave_conflicts', [
            'teacher_id' => $teacher->id, 'attendance_date' => '2026-10-05', 'teacher_leave_id' => $leaveId,
            'leave_type' => 'Vacation Leave', 'first_scanned_at' => '2026-10-05 06:58:00', 'reviewed_at' => null,
        ]);
        $this->assertDatabaseHas('teacher_leaves', ['id' => $leaveId, 'status' => 'Approved', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-06']);
    }

    public function test_more_scans_on_the_same_leave_day_flag_it_only_once(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->leave($teacher, '2026-10-05', '2026-10-05');

        $this->scan($teacher, '2026-10-05 06:58:00')->assertJson(['success' => true]);
        $this->scan($teacher, '2026-10-05 15:05:00')->assertJson(['success' => true, 'kind' => 'OUT', 'leave_conflict' => true]);

        $this->assertSame(1, DB::table('attendance_leave_conflicts')->where('teacher_id', $teacher->id)->count());
    }

    public function test_a_scan_outside_a_leave_is_not_flagged(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->leave($teacher, '2026-10-06', '2026-10-07');

        $this->scan($teacher, '2026-10-05 06:58:00')
            ->assertJson(['success' => true, 'leave_conflict' => false])
            ->assertJsonPath('message', fn (string $message) => ! str_contains($message, Scanner::SCAN_ON_LEAVE_DAY));

        $this->assertDatabaseCount('attendance_leave_conflicts', 0);
    }

    public function test_a_flagged_leave_day_scan_is_on_pending_review_until_it_is_marked_reviewed(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher(['fullname' => 'Ana Cruz']);
        $this->leave($teacher, '2026-10-05', '2026-10-05');
        $this->scan($teacher, '2026-10-05 06:58:00');
        $conflictId = (int) DB::table('attendance_leave_conflicts')->value('id');
        $superAdmin = $this->account('superadmin', ['email' => 'aide@test.local']);

        $pending = collect($this->actingAs($superAdmin)->getJson('/data/pending-review?teacher_id='.$teacher->id)->json('pending'))
            ->firstWhere('leave_conflict_id', $conflictId);
        $this->assertSame(
            ['date' => '2026-10-05', 'reason' => 'Scan on Leave Day', 'real_in' => '06:58:00', 'leave_type' => 'Vacation Leave'],
            array_intersect_key($pending, array_flip(['date', 'reason', 'leave_type', 'real_in']))
        );

        $this->postJson('/data/leave-conflicts/review', ['id' => $conflictId, 'remarks' => 'Came in to hand over grades'])
            ->assertJson(['success' => true, 'message' => 'Marked as reviewed.']);

        $this->assertNull(collect($this->getJson('/data/pending-review?teacher_id='.$teacher->id)->json('pending'))->firstWhere('leave_conflict_id', $conflictId));
        $this->assertDatabaseHas('attendance_leave_conflicts', ['id' => $conflictId, 'reviewed_by' => 'aide@test.local', 'review_remarks' => 'Came in to hand over grades']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_conflict.reviewed', 'subject_id' => $teacher->id, 'actor' => 'aide@test.local']);
        $this->postJson('/data/leave-conflicts/review', ['id' => $conflictId])
            ->assertJson(['success' => false, 'message' => 'This entry was already reviewed or does not exist.']);
    }

    public function test_an_adjustment_for_the_day_also_resolves_a_flagged_leave_day_scan(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher();
        $this->leave($teacher, '2026-10-05', '2026-10-05');
        $this->scan($teacher, '2026-10-05 06:58:00');
        $this->actingAs($this->account('superadmin'));

        $this->postJson('/data/adjustments', [
            'teacher_id' => $teacher->id, 'adjustment_date' => '2026-10-05', 'adjustment_type' => 'Other', 'adjustment_type_other' => 'Came in before leave', 'remarks' => 'Half day before leave',
        ])->assertJson(['success' => true]);

        $this->assertNull(collect($this->getJson('/data/pending-review?teacher_id='.$teacher->id)->json('pending'))->firstWhere('reason', 'Scan on Leave Day'));
    }

    public function test_a_scan_is_recorded_for_the_servers_date_whatever_date_the_kiosk_sends(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher();

        $this->scan($teacher, '2026-10-05 06:58:00', ['date' => '2026-12-25', 'attendance_date' => '2026-12-25', 'time' => '06:00:00'])
            ->assertJson(['success' => true]);

        $this->assertSame(['2026-10-05'], DB::table('attendance')->where('teacher_id', $teacher->id)->pluck('date')->all());
        $this->assertDatabaseHas('attendance', ['teacher_id' => $teacher->id, 'am_arrival' => '06:58:00']);
    }

    public function test_adding_leave_records_who_added_it_and_takes_effect_at_once(): void
    {
        $teacher = $this->teacher();
        $superAdmin = $this->account('superadmin', ['email' => 'aide@test.local']);

        $this->actingAs($superAdmin)->post('/superadmin/personnel/leave', [
            'leave_teacher_id' => $teacher->id, 'leave_type' => 'Sick Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-06', 'leave_reason' => 'Flu',
        ])->assertRedirect();

        $this->assertDatabaseHas('teacher_leaves', [
            'teacher_id' => $teacher->id, 'leave_type' => 'Sick Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-06',
            'reason' => 'Flu', 'status' => 'Approved', 'created_by' => 'aide@test.local',
        ]);
        $this->assertNotNull(DB::table('teacher_leaves')->where('teacher_id', $teacher->id)->value('created_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.created', 'subject_id' => $teacher->id, 'actor' => 'aide@test.local']);
        $this->getJson('/data/teacher-leaves?teacher_id='.$teacher->id)->assertJsonPath('leaves.0.leave_type', 'Sick Leave');
    }

    public function test_the_dtr_data_of_a_leave_day_keeps_the_days_scans(): void
    {
        $teacher = $this->teacher();
        $this->leave($teacher, '2026-10-05', '2026-10-05');
        DB::table('attendance')->insert(['teacher_id' => $teacher->id, 'date' => '2026-10-05', 'am_arrival' => '06:58:00', 'pm_departure' => '15:02:00']);

        $this->actingAs($this->account('admin'))
            ->getJson('/data/dtr-month?teacher_id='.$teacher->id.'&month=2026-10')
            ->assertJsonPath('teacher.records', [
                ['date' => '2026-10-05', 'am_arrival' => '06:58:00', 'am_departure' => null, 'pm_arrival' => null, 'pm_departure' => '15:02:00'],
            ]);
    }
}
