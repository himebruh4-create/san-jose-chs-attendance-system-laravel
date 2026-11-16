<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The Super Admin dashboard's warning about scan photos the daily prune
 * (02:00, 30 days) deletes within the next 3 days: how many, and the next
 * deletion with its count. Nobody else sees it; nothing is deleted by it.
 */
class ScanPhotoDeletionWarningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // Wednesday 10:00; today's 02:00 prune has already run. The next runs
        // in the window: Thu Oct 8, Fri Oct 9 and Sat Oct 10, each at 02:00.
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00', 'Asia/Manila'));
    }

    private function photo(string $scannedAt, bool $withPhoto = true): void
    {
        $path = 'scan-photos/'.str_replace('-', '/', substr($scannedAt, 0, 10)).'/'.uniqid().'.webp';
        if ($withPhoto) {
            Storage::disk('local')->put($path, 'webp');
        }

        DB::table('scan_photos')->insert([
            'attendance_date' => substr($scannedAt, 0, 10),
            'scanned_at' => $scannedAt,
            'outcome' => 'accepted',
            'scan_kind' => 'IN',
            'path' => $withPhoto ? $path : null,
        ]);
    }

    private function dashboard(string $role = 'superadmin')
    {
        return $this->actingAs($this->account($role))->get(route($role.'.dashboard'))->assertOk();
    }

    public function test_the_super_admin_sees_how_many_photos_go_within_3_days_and_the_next_deletion(): void
    {
        $this->photo('2026-09-07 12:00:00');            // Thu Oct 8 run
        $this->photo('2026-09-08 01:00:00');            // Thu Oct 8 run
        $this->photo('2026-09-09 15:00:00');            // Sat Oct 10 run
        $this->photo('2026-09-10 03:00:00');            // after the window
        $this->photo('2026-09-07 08:00:00', withPhoto: false); // a scan without a photo is not counted

        $this->dashboard()
            ->assertSee('Scan Photos About to Be Deleted')
            ->assertSeeInOrder(['3 scan photos', 'will be deleted within the next 3 days.', 'Next deletion:',
                'Thursday, Oct 8, 2026 at 2:00 AM', '(2 photos).'], false)
            ->assertSee(route('superadmin.scan-photos'));
    }

    public function test_the_next_deletion_is_the_first_run_that_deletes_a_photo(): void
    {
        $this->photo('2026-09-09 15:00:00');

        $this->dashboard()
            ->assertSeeInOrder(['1 scan photo', 'Saturday, Oct 10, 2026 at 2:00 AM', '(1 photo).'], false);
    }

    #[TestWith(['2026-09-10 03:00:00'])]
    #[TestWith(['2026-10-01 07:00:00'])]
    public function test_no_warning_when_no_photo_is_deleted_within_3_days(string $scannedAt): void
    {
        $this->photo($scannedAt);

        $this->dashboard()->assertDontSee('Scan Photos About to Be Deleted');
    }

    public function test_the_warning_follows_the_real_deletion(): void
    {
        $this->photo('2026-09-07 12:00:00');
        $this->photo('2026-09-09 15:00:00');

        $this->travelTo(Carbon::parse('2026-10-08 02:00:00', 'Asia/Manila'));
        $this->artisan('attendance:prune-scan-photos')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-08 02:00:01', 'Asia/Manila'));

        $this->assertSame(1, DB::table('scan_photos')->count());
        $this->dashboard()->assertSeeInOrder(['1 scan photo', 'Saturday, Oct 10, 2026 at 2:00 AM', '(1 photo).'], false);
    }

    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_admin_and_principal_never_see_the_warning(string $role): void
    {
        $this->photo('2026-09-07 12:00:00');

        $this->dashboard($role)->assertDontSee('Scan Photos About to Be Deleted')->assertDontSee('will be deleted within');
    }
}
