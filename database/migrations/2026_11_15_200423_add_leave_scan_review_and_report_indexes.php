<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Leave rules (2026-11):
//   - teacher_leaves.created_by: the Super Admin who added the leave
//     (created_at already holds when). Earlier leaves stay NULL.
//   - attendance_leave_conflicts: a kiosk scan recorded on a day covered by
//     an approved leave. The scan is stored as usual; this row puts the day
//     on Pending Review until the Super Admin reviews it. The leave itself
//     is never changed. One row per person per day; no foreign keys, so the
//     evidence outlives a removed leave or person (as with
//     attendance_rejected_scans).
// Indexes for the per-person leave lookups and the active-events query
// every report runs (attendance already has (teacher_id, date) and (date)).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_leaves', function (Blueprint $table) {
            $table->string('created_by', 150)->nullable()->after('status');
            $table->index(['teacher_id', 'status', 'leave_from'], 'idx_leaves_teacher_status_from');
        });

        Schema::table('school_events', function (Blueprint $table) {
            $table->index(['status', 'date_from'], 'idx_events_status_from');
        });

        Schema::create('attendance_leave_conflicts', function (Blueprint $table) {
            $table->id();
            // No foreign key (as attendance_rejected_scans): the review record
            // outlives the person and never blocks a permanent delete.
            $table->unsignedBigInteger('teacher_id');
            $table->date('attendance_date');
            $table->unsignedBigInteger('teacher_leave_id')->nullable();
            $table->string('leave_type', 100)->nullable();
            $table->dateTime('first_scanned_at');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reviewed_by', 150)->nullable();
            $table->string('review_remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['teacher_id', 'attendance_date']);
            $table->index('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_conflicts');

        Schema::table('school_events', function (Blueprint $table) {
            $table->dropIndex('idx_events_status_from');
        });

        Schema::table('teacher_leaves', function (Blueprint $table) {
            $table->dropIndex('idx_leaves_teacher_status_from');
            $table->dropColumn('created_by');
        });
    }
};
