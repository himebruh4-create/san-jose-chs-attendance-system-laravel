<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kiosk camera / offline alerts for the Super Admin and Admin (text only,
// never a photo). open_device_id holds the kiosk's id while the alert is
// open and NULL once it is closed, so the unique index allows only one
// open alert per kiosk.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiosk_device_id')->constrained('kiosk_devices')->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('started_at');
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->unsignedBigInteger('open_device_id')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_alerts');
    }
};
