<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Scan photos are taken on Time In and Lunch In only (2026-11). A scan of a
// barcode that matches nobody no longer keeps a photo, so its attempted
// barcode goes to the Rejected Scan Log as text instead: teacher_id is NULL
// for those rows and barcode holds what was scanned or typed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_rejected_scans', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id')->nullable()->change();
            $table->string('barcode', 100)->nullable()->after('teacher_id');
        });
    }

    public function down(): void
    {
        DB::table('attendance_rejected_scans')->whereNull('teacher_id')->delete();

        Schema::table('attendance_rejected_scans', function (Blueprint $table) {
            $table->dropColumn('barcode');
            $table->unsignedBigInteger('teacher_id')->nullable(false)->change();
        });
    }
};
