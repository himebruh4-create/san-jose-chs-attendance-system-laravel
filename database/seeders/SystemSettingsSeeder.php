<?php

namespace Database\Seeders;

use App\Domain\Attendance\BreakConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingsSeeder extends Seeder
{
    /**
     * The one settings row (id = 1) every part of the system reads. A fresh
     * install of the native-PHP system had no such row, which broke the
     * kiosk's status check.
     */
    public function run(): void
    {
        if (DB::table('system_settings')->where('id', 1)->exists()) {
            return;
        }

        DB::table('system_settings')->insert([
            'id' => 1,
            'attendance_recording_enabled' => true,
            'disabled_reason' => null,
            'lunch_out' => '12:15:00',
            'lunch_in' => '13:00:00',
            'schedule_breaks' => json_encode(BreakConfig::defaultEntries()),
        ]);
    }
}
