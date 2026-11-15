<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Attendance Adjustments (Super Admin): no adjustment for a future date,
 * adjustments on a leave day, the paged list, and an adjustment taking its
 * day off Pending Review.
 */
class AttendanceAdjustmentsTest extends TestCase
{
    private ?Account $superAdmin = null;

    private function superAdmin(): Account
    {
        return $this->superAdmin ??= $this->account('superadmin', ['email' => 'aide@test.local']);
    }

    private function addAdjustment(Teacher $teacher, string $date, array $fields = [])
    {
        return $this->actingAs($this->superAdmin())->postJson('/data/adjustments', $fields + [
            'teacher_id' => $teacher->id, 'adjustment_date' => $date, 'adjustment_type' => 'Forgot to Scan',
            'am_arrival' => '07:00', 'pm_departure' => '15:00', 'remarks' => 'Kiosk was offline',
        ]);
    }

    private function leave(Teacher $teacher, string $from, string $until): int
    {
        return DB::table('teacher_leaves')->insertGetId([
            'teacher_id' => $teacher->id, 'leave_type' => 'Sick Leave', 'leave_from' => $from, 'leave_until' => $until, 'reason' => 'Flu',
        ]);
    }

    public function test_an_adjustment_for_a_date_after_today_is_rejected_on_the_server(): void
    {
        $teacher = $this->teacher();
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->addAdjustment($teacher, $tomorrow)
            ->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'Cannot add an adjustment for a future date.']);

        $this->assertDatabaseMissing('attendance_adjustments', ['teacher_id' => $teacher->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'adjustment.created']);
    }

    public function test_an_adjustment_for_today_is_saved(): void
    {
        $teacher = $this->teacher();
        $today = date('Y-m-d');

        $this->addAdjustment($teacher, $today)->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendance_adjustments', ['teacher_id' => $teacher->id, 'adjustment_date' => $today]);
    }

    public function test_an_adjustment_on_a_leave_day_is_saved_with_its_reason_and_audited_with_the_leave(): void
    {
        $teacher = $this->teacher();
        $leaveId = $this->leave($teacher, '2026-10-05', '2026-10-06');

        $id = $this->addAdjustment($teacher, '2026-10-05', ['remarks' => 'Came in for the district meeting'])
            ->assertJson(['success' => true])
            ->json('adjustment_id');

        $this->assertDatabaseHas('attendance_adjustments', ['id' => $id, 'remarks' => 'Came in for the district meeting']);
        $this->assertDatabaseHas('teacher_leaves', ['id' => $leaveId, 'status' => 'Approved', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-06']);

        $details = json_decode(DB::table('audit_logs')->where('action', 'adjustment.created')->where('subject_id', $id)->value('details'), true);
        $this->assertSame('Came in for the district meeting', $details['reason']);
        $this->assertSame(['leave_id' => $leaveId, 'type' => 'Sick Leave'], $details['on_leave']);
    }

    public function test_an_adjustment_on_a_leave_day_without_a_reason_is_rejected(): void
    {
        $teacher = $this->teacher();
        $this->leave($teacher, '2026-10-05', '2026-10-05');

        $this->addAdjustment($teacher, '2026-10-05', ['remarks' => '  '])
            ->assertExactJson(['success' => false, 'message' => 'Remarks are required.']);

        $this->assertDatabaseMissing('attendance_adjustments', ['teacher_id' => $teacher->id]);
    }

    public function test_the_adjustment_form_shows_the_leave_covering_the_day(): void
    {
        $teacher = $this->teacher();
        $this->leave($teacher, '2026-10-05', '2026-10-07');

        $this->actingAs($this->superAdmin());

        $this->getJson('/data/day-real-scans?teacher_id='.$teacher->id.'&date=2026-10-06')
            ->assertJsonPath('leave', ['leave_type' => 'Sick Leave', 'leave_from' => '2026-10-05', 'leave_until' => '2026-10-07', 'reason' => 'Flu']);
        $this->getJson('/data/day-real-scans?teacher_id='.$teacher->id.'&date=2026-10-08')
            ->assertJsonPath('leave', null);
    }

    public function test_an_adjustment_takes_its_day_off_pending_review_and_puts_it_on_the_adjustments_list(): void
    {
        $teacher = $this->teacher(['fullname' => 'Ana Cruz', 'created_at' => '2026-10-05 00:00:00']);
        DB::table('system_settings')->where('id', 1)->update(['review_start_date' => '2026-10-05']);
        $this->actingAs($this->superAdmin());

        $before = $this->getJson('/data/pending-review')->json();
        $this->assertContains('2026-10-05', array_column($before['pending'], 'date'));

        $this->addAdjustment($teacher, '2026-10-05')->assertJson(['success' => true]);

        $after = $this->getJson('/data/pending-review')->json();
        $this->assertNotContains('2026-10-05', array_column($after['pending'], 'date'));
        $this->assertSame($before['counts']['total'] - 1, $after['counts']['total']);
        $this->assertSame($after['counts']['total'], $this->getJson('/data/review-summary')->json('total'));
        $this->getJson('/data/adjustments')
            ->assertJsonPath('adjustments.0.fullname', 'Ana Cruz')
            ->assertJsonPath('adjustments.0.adjustment_date', '2026-10-05');
    }

    public function test_the_pending_review_count_runs_the_same_number_of_queries_however_many_personnel(): void
    {
        DB::table('system_settings')->where('id', 1)->update(['review_start_date' => '2026-10-05']);
        $this->teacher(['created_at' => '2026-10-05 00:00:00']);
        $queriesFor = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->superAdmin())->getJson('/data/review-summary')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $queriesFor();   // the first request also registers the session (one device per account)
        $withOne = $queriesFor();
        foreach (range(1, 4) as $n) {
            $this->teacher(['created_at' => '2026-10-05 00:00:00']);
        }

        $this->assertSame($withOne, $queriesFor());
    }

    public function test_the_adjustments_list_loads_newest_first_one_page_at_a_time(): void
    {
        $teacher = $this->teacher();
        // 26 days: one more than a page (AdjustmentController::PER_PAGE = 25).
        foreach (range(0, 25) as $n) {
            DB::table('attendance_adjustments')->insert([
                'teacher_id' => $teacher->id, 'adjustment_date' => date('Y-m-d', strtotime("2026-09-01 +{$n} days")),
                'reason' => 'Forgot to Scan', 'adjustment_type' => 'Forgot to Scan',
            ]);
        }
        $this->actingAs($this->superAdmin());

        $first = $this->getJson('/data/adjustments?page=1');
        $second = $this->getJson('/data/adjustments?page=2');
        $pastTheEnd = $this->getJson('/data/adjustments?page=9');

        $first->assertJson(['total' => 26, 'page' => 1, 'last_page' => 2])->assertJsonCount(25, 'adjustments')
            ->assertJsonPath('adjustments.0.adjustment_date', '2026-09-26');
        $this->assertSame(['2026-09-01'], array_column($second->json('adjustments'), 'adjustment_date'));
        $pastTheEnd->assertJson(['page' => 2, 'last_page' => 2]);
    }

    private function savedAdjustment(Teacher $teacher): int
    {
        return $this->addAdjustment($teacher, '2026-10-05', ['adjustment_type' => 'Missing Time-In', 'remarks' => 'Kiosk was offline'])
            ->assertJson(['success' => true])
            ->json('adjustment_id');
    }

    public function test_an_adjustments_times_and_remarks_can_be_edited_and_the_change_is_audited(): void
    {
        $teacher = $this->teacher();
        $id = $this->savedAdjustment($teacher);

        $this->postJson('/data/adjustments/update', [
            'id' => $id, 'am_arrival' => '07:05', 'pm_departure' => '15:15', 'remarks' => 'Arrival scan not recorded.',
        ])->assertExactJson(['success' => true, 'message' => 'Attendance adjustment updated.', 'adjustment_id' => $id]);

        $this->assertDatabaseHas('attendance_adjustments', [
            'id' => $id, 'teacher_id' => $teacher->id, 'adjustment_date' => '2026-10-05', 'adjustment_type' => 'Missing Time-In',
            'am_arrival' => '07:05:00', 'am_departure' => null, 'pm_arrival' => null, 'pm_departure' => '15:15:00',
            'remarks' => 'Arrival scan not recorded.',
        ]);
        $details = json_decode(DB::table('audit_logs')->where('action', 'adjustment.updated')->where('subject_id', $id)->value('details'), true);
        $this->assertSame([
            'teacher_id' => $teacher->id,
            'date' => '2026-10-05',
            'before' => ['am_arrival' => '07:00:00', 'pm_departure' => '15:00:00', 'remarks' => 'Kiosk was offline'],
            'after' => ['am_arrival' => '07:05:00', 'pm_departure' => '15:15:00', 'remarks' => 'Arrival scan not recorded.'],
        ], $details);
    }

    public function test_saving_an_adjustment_without_changes_writes_nothing(): void
    {
        $id = $this->savedAdjustment($this->teacher());

        $this->postJson('/data/adjustments/update', ['id' => $id, 'am_arrival' => '07:00', 'pm_departure' => '15:00', 'remarks' => 'Kiosk was offline'])
            ->assertJson(['success' => true, 'message' => 'No changes to save.']);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'adjustment.updated']);
    }

    public function test_an_edit_with_bad_times_or_no_remarks_is_rejected_and_changes_nothing(): void
    {
        $id = $this->savedAdjustment($this->teacher());

        $this->postJson('/data/adjustments/update', ['id' => $id, 'am_arrival' => '25:00', 'remarks' => 'x'])
            ->assertExactJson(['success' => false, 'message' => 'Please enter valid times.']);
        $this->postJson('/data/adjustments/update', ['id' => $id, 'am_arrival' => '07:05', 'remarks' => '  '])
            ->assertExactJson(['success' => false, 'message' => 'Remarks are required.']);

        $this->assertDatabaseHas('attendance_adjustments', ['id' => $id, 'am_arrival' => '07:00:00', 'remarks' => 'Kiosk was offline']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'adjustment.updated']);
    }

    public function test_an_archived_or_missing_adjustment_cannot_be_edited(): void
    {
        $id = $this->savedAdjustment($this->teacher());
        $this->postJson('/data/adjustments/delete', ['id' => $id])->assertJson(['success' => true]);

        foreach ([$id, 999999] as $target) {
            $this->postJson('/data/adjustments/update', ['id' => $target, 'am_arrival' => '07:05', 'remarks' => 'x'])
                ->assertExactJson(['success' => false, 'message' => 'Attendance adjustment was not found.']);
        }
    }

    public function test_only_the_super_admin_can_edit_an_adjustment(): void
    {
        $id = $this->savedAdjustment($this->teacher());
        $this->flushSession();

        $this->actingAs($this->account('admin'))
            ->postJson('/data/adjustments/update', ['id' => $id, 'am_arrival' => '07:05', 'remarks' => 'x'])
            ->assertForbidden();

        $this->assertDatabaseHas('attendance_adjustments', ['id' => $id, 'am_arrival' => '07:00:00']);
    }

    public function test_the_review_window_lets_the_times_and_remarks_be_edited(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('superadmin.adjustments'))->getContent();

        foreach (['reviewAMArrival', 'reviewAMDeparture', 'reviewPMArrival', 'reviewPMDeparture', 'reviewRemarks'] as $field) {
            $this->assertDoesNotMatchRegularExpression('#id="'.$field.'"[^>]*readonly#s', $html, $field);
        }
        $this->assertStringContainsString('onclick="saveReviewedAdjustment()"', $html);
    }
}
