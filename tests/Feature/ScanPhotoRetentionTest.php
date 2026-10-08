<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `attendance:prune-scan-photos`: kiosk photos are kept for 30 days, and
 * this week's scans are never pruned because the Principal's card counts them.
 */
class ScanPhotoRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // Wednesday; the week started Monday 2026-10-05.
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00', 'Asia/Manila'));
    }

    private function photo(string $scannedAt, string $outcome = 'accepted'): int
    {
        $path = 'scan-photos/'.str_replace('-', '/', substr($scannedAt, 0, 10)).'/'.uniqid().'.jpg';
        Storage::disk('local')->put($path, 'jpeg');

        return DB::table('scan_photos')->insertGetId([
            'attendance_date' => substr($scannedAt, 0, 10),
            'scanned_at' => $scannedAt,
            'outcome' => $outcome,
            'path' => $path,
        ]);
    }

    public function test_photos_older_than_30_days_are_deleted_with_their_files(): void
    {
        $old = $this->photo('2026-09-07 09:59:59');
        $kept = $this->photo('2026-09-07 10:00:01');
        $oldPath = DB::table('scan_photos')->where('id', $old)->value('path');

        $this->artisan('attendance:prune-scan-photos')
            ->expectsOutput('1 kiosk photo(s) older than 30 days deleted.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('scan_photos', ['id' => $old]);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertDatabaseHas('scan_photos', ['id' => $kept]);
    }

    public function test_this_weeks_scans_are_kept_and_counted_even_with_a_shorter_retention(): void
    {
        $this->photo('2026-10-05 07:00:00', 'duplicate');
        $this->photo('2026-10-06 07:00:00', 'not_found');
        $lastWeek = $this->photo('2026-10-04 23:59:59', 'duplicate');

        $this->artisan('attendance:prune-scan-photos', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('scan_photos', ['id' => $lastWeek]);
        $this->assertDatabaseCount('scan_photos', 2);
        $this->actingAs($this->account('principal'))
            ->getJson('/principal/dashboard/scan-verification')
            ->assertJson(['flagged_week' => 2, 'repeat_week' => 1, 'unknown_week' => 1]);
    }
}
