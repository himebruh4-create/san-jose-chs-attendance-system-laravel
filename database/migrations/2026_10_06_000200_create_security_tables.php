<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// New in the Laravel version.
return new class extends Migration
{
    public function up(): void
    {
        // Browsers the Super Admin has authorized as attendance kiosks. Only
        // a browser holding one of these tokens (a long-lived HttpOnly
        // cookie) can record a scan. The token itself is never stored.
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('created_by', 150);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Who changed what. Written by App\Support\Audit for every change to
        // attendance, personnel, accounts and settings.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('actor', 150);
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        // Webcam photo taken by the kiosk at the moment of each scan,
        // accepted or rejected. The image file is stored outside the web
        // root (storage/app/private/scan-photos).
        Schema::create('scan_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('barcode', 100)->nullable();
            $table->date('attendance_date');
            $table->dateTime('scanned_at');
            $table->string('outcome', 30);
            $table->string('scan_kind', 30)->nullable();
            $table->string('path')->nullable();
            $table->foreignId('kiosk_device_id')->nullable()->constrained('kiosk_devices')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['teacher_id', 'attendance_date']);
            $table->index('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_photos');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('kiosk_devices');
    }
};
