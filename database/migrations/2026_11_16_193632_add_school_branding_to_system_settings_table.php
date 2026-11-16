<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The school's name and logo (Settings -> School Name & Logo), read through
// App\Support\SchoolBranding. NULL means the built-in default: the full
// name on printouts and badges, the short name in the sidebar and browser
// tab titles, and public/img/logo.png. school_logo_path is a file on the
// private disk, served by GET /school-logo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->string('school_name', 120)->nullable()->after('review_reminder_dismissed_period');
            $table->string('school_short_name', 40)->nullable()->after('school_name');
            $table->string('school_logo_path')->nullable()->after('school_short_name');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['school_name', 'school_short_name', 'school_logo_path']);
        });
    }
};
