<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How the barcode reached the kiosk: "scanned" by the USB scanner or "typed"
// by hand (pasted counts as typed). Typed scans carry the manual_entry flag.
// Rows recorded before this column existed are taken as scanned.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_photos', function (Blueprint $table) {
            $table->string('input_method', 10)->default('scanned')->after('scan_kind');
        });
    }

    public function down(): void
    {
        Schema::table('scan_photos', function (Blueprint $table) {
            $table->dropColumn('input_method');
        });
    }
};
