<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

/**
 * Kiosk scanning end to end: routing, the repeat guard, lunch scans,
 * completed shifts, archived personnel, recording switch, photos and the
 * manual-entry (typed barcode) flag.
 */
class KioskScanTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->token = $this->kioskToken();
    }

    private function scan(string $barcode, string $at, ?string $photo = null, ?string $inputMethod = null)
    {
        $this->travelTo(Carbon::parse($at));

        return $this->withCredentials()
            ->withCookie(KioskDevice::COOKIE, $this->token)
            ->postJson('/kiosk/scan', array_filter(['barcode' => $barcode, 'photo' => $photo, 'input_method' => $inputMethod]));
    }

    /** @return array<string, int> The Principal's flag counts for the current week. */
    private function principalFlagCounts(): array
    {
        return $this->actingAs($this->account('principal'))
            ->getJson('/principal/dashboard/scan-verification')
            ->json();
    }

    public function test_a_regular_teachers_day_in_lunch_out_lunch_in_and_out(): void
    {
        // 2026-10-05 is a Monday; schedule 07:00–15:00, lunch 12:15–13:00.
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:58:00')
            ->assertJson(['success' => true, 'title' => 'AM Arrival', 'kind' => 'IN', 'status' => 'On-Time', 'position' => 'Teacher I']);

        $this->scan($t->barcode, '2026-10-05 12:16:00')
            ->assertJson(['success' => true, 'title' => 'AM Departure', 'kind' => 'BREAK_OUT']);

        $this->scan($t->barcode, '2026-10-05 12:58:00')
            ->assertJson(['success' => true, 'title' => 'PM Arrival', 'kind' => 'BREAK_IN']);

        $this->scan($t->barcode, '2026-10-05 15:02:00')
            ->assertJson(['success' => true, 'title' => 'PM Departure', 'kind' => 'OUT']);

        $this->assertDatabaseHas('attendance', [
            'teacher_id' => $t->id, 'date' => '2026-10-05',
            'am_arrival' => '06:58:00', 'am_departure' => '12:16:00',
            'pm_arrival' => '12:58:00', 'pm_departure' => '15:02:00',
            'attendance_status' => 'Complete PM',
        ]);

        // Anything after the OUT is refused and logged, not stored.
        $this->scan($t->barcode, '2026-10-05 16:30:00')
            ->assertJson(['success' => false, 'title' => 'Attendance Already Complete']);

        $this->assertDatabaseHas('attendance_rejected_scans', ['teacher_id' => $t->id, 'reason' => 'ATTENDANCE_COMPLETE']);
    }

    public function test_a_late_arrival_is_reported_as_late(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 07:00:01')->assertJson(['success' => true, 'status' => 'Late']);
    }

    public function test_a_repeat_scan_within_five_minutes_is_rejected(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:50:00')->assertJson(['success' => true]);
        $this->scan($t->barcode, '2026-10-05 06:54:59')->assertJson(['success' => false, 'title' => 'Duplicate Scan']);

        $this->assertDatabaseHas('attendance_rejected_scans', ['teacher_id' => $t->id, 'reason' => 'DUPLICATE_SCAN']);
        $this->assertSame('06:50:00', DB::table('attendance')->where('teacher_id', $t->id)->value('am_arrival'));
    }

    public function test_an_overnight_guard_shift_closes_on_the_next_day(): void
    {
        // Friday 22:00 → Saturday 06:00.
        $guard = $this->teacher([], '22:00:00', '06:00:00', ['Friday']);

        $this->scan($guard->barcode, '2026-10-09 21:55:00')->assertJson(['success' => true, 'title' => 'PM Arrival']);
        $this->scan($guard->barcode, '2026-10-10 06:03:00')->assertJson(['success' => true, 'title' => 'PM Departure']);

        // The OUT belongs to Friday's shift, not a new Saturday row.
        $this->assertDatabaseHas('attendance', ['teacher_id' => $guard->id, 'date' => '2026-10-09', 'pm_arrival' => '21:55:00', 'pm_departure' => '06:03:00']);
        $this->assertDatabaseMissing('attendance', ['teacher_id' => $guard->id, 'date' => '2026-10-10']);
    }

    public function test_unknown_and_archived_barcodes_are_refused(): void
    {
        $t = $this->teacher();
        $t->forceFill(['is_deleted' => true])->save();

        $this->scan('999999', '2026-10-05 07:00:00')->assertJson(['success' => false, 'title' => 'Not Found']);
        $this->scan($t->barcode, '2026-10-05 07:00:00')->assertJson(['success' => false, 'title' => 'Not Found']);

        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_repeated_unknown_barcodes_pause_the_kiosk(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->scan((string) (100000 + $i), '2026-10-05 07:00:00')->assertJson(['title' => 'Not Found']);
        }

        $this->scan('123456', '2026-10-05 07:00:10')->assertStatus(429)->assertJson(['title' => 'Please Wait']);
    }

    public function test_disabled_recording_stores_nothing(): void
    {
        $t = $this->teacher();
        DB::table('system_settings')->update(['attendance_recording_enabled' => 0, 'disabled_reason' => 'Summer Break']);

        $this->scan($t->barcode, '2026-10-05 07:00:00')
            ->assertJson(['success' => false, 'title' => 'Recording Disabled', 'message' => 'Summer Break']);

        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_the_webcam_photo_is_re_encoded_and_stored_privately(): void
    {
        $t = $this->teacher();

        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);
        $dataUrl = 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());

        $this->scan($t->barcode, '2026-10-05 06:55:00', $dataUrl)->assertJson(['success' => true]);
        // A scan without a picture is still recorded as such.
        $this->scan($t->barcode, '2026-10-05 12:20:00')->assertJson(['success' => true]);

        $photos = DB::table('scan_photos')->where('teacher_id', $t->id)->orderBy('id')->get();
        $this->assertCount(2, $photos);
        $this->assertSame('accepted', $photos[0]->outcome);
        $this->assertStringStartsWith('scan-photos/2026/10/05/', $photos[0]->path);
        Storage::disk('local')->assertExists($photos[0]->path);
        $this->assertNull($photos[1]->path);

        // Only a Super Admin can view it.
        $this->actingAs($this->account('admin'))->get(route('photos.scan', $photos[0]->id))->assertRedirect(route('admin.dashboard'));
        $this->flushSession();
        // Never kept in the browser cache (shared office PC).
        $this->actingAs($this->account('superadmin'))->get(route('photos.scan', $photos[0]->id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_non_image_photo_is_ignored(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:55:00', 'data:image/jpeg;base64,'.base64_encode('<?php echo 1;'))
            ->assertJson(['success' => true]);

        $this->assertNull(DB::table('scan_photos')->value('path'));
    }

    public function test_a_typed_barcode_is_saved_and_flagged_as_a_manual_entry(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:55:00', inputMethod: 'typed')
            ->assertJson(['success' => true, 'title' => 'AM Arrival']);

        $this->assertDatabaseHas('attendance', ['teacher_id' => $t->id, 'date' => '2026-10-05', 'am_arrival' => '06:55:00']);
        $this->assertDatabaseHas('scan_photos', ['teacher_id' => $t->id, 'outcome' => 'accepted', 'input_method' => 'typed']);
        $this->assertSame(1, $this->principalFlagCounts()['manual_week']);
    }

    public function test_a_scanned_barcode_is_not_flagged_as_a_manual_entry(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:55:00', inputMethod: 'scanned')->assertJson(['success' => true]);

        $this->assertDatabaseHas('scan_photos', ['teacher_id' => $t->id, 'outcome' => 'accepted', 'input_method' => 'scanned']);
        $this->assertSame(0, $this->principalFlagCounts()['manual_week']);
    }

    #[TestWith([null])]
    #[TestWith(['keyboard'])]
    #[TestWith(['TYPED'])]
    public function test_a_missing_or_unknown_input_method_counts_as_scanned_and_attendance_is_saved(?string $inputMethod): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:55:00', inputMethod: $inputMethod)
            ->assertJson(['success' => true, 'title' => 'AM Arrival']);

        $this->assertDatabaseHas('attendance', ['teacher_id' => $t->id, 'date' => '2026-10-05', 'am_arrival' => '06:55:00']);
        $this->assertDatabaseHas('scan_photos', ['teacher_id' => $t->id, 'input_method' => 'scanned']);
    }

    public function test_a_typed_repeat_scan_carries_both_the_repeat_and_manual_entry_flags(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:50:00', inputMethod: 'typed')->assertJson(['success' => true]);
        $this->scan($t->barcode, '2026-10-05 06:52:00', inputMethod: 'typed')->assertJson(['success' => false, 'title' => 'Duplicate Scan']);

        $this->assertDatabaseHas('scan_photos', ['teacher_id' => $t->id, 'outcome' => 'duplicate', 'input_method' => 'typed']);
        $counts = $this->principalFlagCounts();
        $this->assertSame(1, $counts['repeat_week']);
        $this->assertSame(2, $counts['manual_week']);
        $this->assertSame(2, $counts['flagged_week']);
    }

    public function test_the_same_person_scanning_twice_within_seconds_is_a_repeat_not_a_rapid_scan(): void
    {
        $t = $this->teacher();

        $this->scan($t->barcode, '2026-10-05 06:55:00')->assertJson(['success' => true]);
        $this->scan($t->barcode, '2026-10-05 06:55:03')->assertJson(['success' => false, 'title' => 'Duplicate Scan']);

        $counts = $this->principalFlagCounts();
        $this->assertSame(1, $counts['repeat_week']);
        $this->assertSame(0, $counts['rapid_week']);
    }

    public function test_a_scan_is_still_saved_when_its_photo_record_fails_and_the_error_is_logged(): void
    {
        $t = $this->teacher();
        Log::spy();
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('Disk full'));
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);
        $dataUrl = 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());

        $this->scan($t->barcode, '2026-10-05 06:55:00', $dataUrl, 'typed')
            ->assertOk()
            ->assertJson(['success' => true, 'title' => 'AM Arrival']);

        $this->assertDatabaseHas('attendance', ['teacher_id' => $t->id, 'date' => '2026-10-05', 'am_arrival' => '06:55:00']);
        $this->assertDatabaseCount('scan_photos', 0);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $context['input_method'] === 'typed' && $context['teacher_id'] === $t->id
        );
    }
}
