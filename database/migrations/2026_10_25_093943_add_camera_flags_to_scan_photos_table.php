<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two more scan flags. camera_blocked: the server found the photo too dark
// or one flat color (lens covered). face_detected: the kiosk's own face
// detection on the photo; NULL when it did not run (no photo, a blocked
// photo, or the detector not loaded), false is the "No face detected" flag.
// Rows recorded before these columns existed carry neither flag.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_photos', function (Blueprint $table) {
            $table->boolean('camera_blocked')->default(false)->after('path');
            $table->boolean('face_detected')->nullable()->after('camera_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('scan_photos', function (Blueprint $table) {
            $table->dropColumn(['camera_blocked', 'face_detected']);
        });
    }
};
