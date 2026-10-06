<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The tables carried over from the native-PHP system (database san_jose_chs),
// with the clean-up the 2026-10 review called for:
//   - attendance gets a real primary key, ONE unique key on (teacher_id, date),
//     a foreign key to teachers, an index on date, and TIME for every scan
//     column (am_departure was varchar). Its legacy columns (id = 0,
//     id_number, fullname, department, schedule_session, status, overtime)
//     are gone; rows whose personnel no longer exist are kept, with every
//     original column, in archived_attendance.
//   - teacher_schedules allows one row per person per weekday.
//   - teachers.schedule_session (NULL in every row) is dropped.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->enum('role', ['superadmin', 'admin', 'principal']);
            $table->rememberToken();
            $table->string('security_question')->nullable();
            $table->string('security_answer_hash')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 150)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('status', ['pending', 'resolved'])->default('pending');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
        });

        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('id_number', 50)->nullable()->unique();
            $table->string('fullname', 150);
            $table->string('department', 100)->nullable();
            $table->string('academic_status', 50)->nullable();
            $table->string('employment_type', 50)->nullable();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('photo')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 150)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('teacher_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('day', 20);
            $table->time('time_in');
            $table->time('time_out');
            $table->unique(['teacher_id', 'day']);
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            // Restrict: removing a person never silently removes attendance.
            // The recycle bin moves their rows to archived_attendance first.
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete()->cascadeOnUpdate();
            $table->date('date');
            $table->time('am_arrival')->nullable();
            $table->time('am_departure')->nullable();
            $table->time('pm_arrival')->nullable();
            $table->time('pm_departure')->nullable();
            $table->string('attendance_status', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['teacher_id', 'date']);
            $table->index('date');
        });

        // Attendance whose personnel record no longer exists: the 61 rows the
        // legacy database already held for deleted test personnel, and the
        // rows of anyone permanently deleted from the recycle bin later.
        // Every legacy column is kept so nothing is lost.
        Schema::create('archived_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_teacher_id');
            $table->string('fullname', 150)->nullable();
            $table->string('department')->nullable();
            $table->date('date');
            $table->time('am_arrival')->nullable();
            $table->time('am_departure')->nullable();
            $table->time('pm_arrival')->nullable();
            $table->time('pm_departure')->nullable();
            $table->string('attendance_status', 50)->nullable();
            $table->string('legacy_id_number', 50)->nullable();
            $table->string('legacy_schedule_session')->nullable();
            $table->string('legacy_status', 20)->nullable();
            $table->string('legacy_overtime', 10)->nullable();
            $table->string('archive_reason');
            $table->timestamp('archived_at')->useCurrent();
            $table->index(['original_teacher_id', 'date']);
        });

        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendance')->nullOnDelete();
            $table->date('adjustment_date');
            $table->time('am_arrival')->nullable();
            $table->time('am_departure')->nullable();
            $table->time('pm_arrival')->nullable();
            $table->time('pm_departure')->nullable();
            $table->string('reason');
            $table->text('remarks')->nullable();
            $table->string('adjustment_type', 100);
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Approved');
            $table->string('approved_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 150)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['teacher_id', 'adjustment_date']);
        });

        // Audit evidence of scans the kiosk refused. No foreign key on
        // purpose: the evidence outlives the personnel record.
        Schema::create('attendance_rejected_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->dateTime('attempted_at');
            $table->date('attendance_date');
            $table->string('reason', 40);
            $table->string('detail')->nullable();
            $table->time('existing_out_time')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reviewed_by', 150)->nullable();
            $table->string('review_remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['teacher_id', 'attendance_date'], 'idx_rejected_teacher_date');
            $table->index('reviewed_at', 'idx_rejected_reviewed');
        });

        Schema::create('confirmed_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->date('absence_date');
            $table->string('confirmed_by', 150)->nullable();
            $table->timestamp('confirmed_at')->useCurrent();
            $table->unique(['teacher_id', 'absence_date']);
        });

        Schema::create('teacher_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('leave_type', 100);
            $table->date('leave_from');
            $table->date('leave_until');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('Approved');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('dtr_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->date('actual_return_date')->nullable();
            $table->enum('status', ['Active', 'Ended', 'Ended Early', 'Cancelled'])->default('Active');
            $table->date('date');
            $table->string('remark_type', 50);
            $table->string('remark_text')->nullable();
            $table->enum('duration', ['Whole Day', 'Half Day'])->default('Whole Day');
            $table->enum('half_day_session', ['AM', 'PM'])->nullable();
            $table->boolean('included_in_total_hours')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['teacher_id', 'date']);
        });

        Schema::create('school_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name');
            $table->string('event_type', 50)->default('Holiday');
            $table->date('date_from');
            $table->date('date_to');
            $table->enum('status', ['Active', 'Inactive', 'Cancelled'])->default('Active');
            $table->enum('duration', ['Whole Day', 'Half Day'])->default('Whole Day');
            $table->boolean('included_in_total_hours')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('remark')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('school_event_personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_event_id')->constrained('school_events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['school_event_id', 'teacher_id']);
        });

        Schema::create('department_options', function (Blueprint $table) {
            $table->id();
            $table->enum('option_type', ['Subject', 'Position']);
            $table->enum('personnel_type', ['Teaching', 'Non-Teaching'])->nullable();
            $table->string('option_name', 100);
            $table->string('status', 20)->default('Active');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('attendance_recording_enabled')->default(true);
            $table->string('disabled_reason')->nullable();
            $table->time('lunch_out')->nullable()->default('12:15:00');
            $table->time('lunch_in')->nullable()->default('13:00:00');
            $table->text('schedule_breaks')->nullable();
            $table->date('review_start_date')->nullable();
            $table->date('review_reminder_dismissed_period')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        foreach ([
            'system_settings', 'department_options', 'school_event_personnel', 'school_events',
            'dtr_remarks', 'teacher_leaves', 'confirmed_absences', 'attendance_rejected_scans',
            'attendance_adjustments', 'archived_attendance', 'attendance', 'teacher_schedules',
            'teachers', 'password_reset_requests', 'accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
