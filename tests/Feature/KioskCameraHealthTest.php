<?php

namespace Tests\Feature;

use App\Models\KioskAlert;
use App\Models\KioskDevice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Kiosk camera health: per-scan "Camera blocked" / "No face detected"
 * flags, and the alerts the Super Admin and Admin get (one open alert per
 * kiosk, closed once the camera works again; "Kiosk offline" when check-ins
 * stop during a scan window). Scans are never refused for a camera problem.
 *
 * 2026-10-05 is a Monday. teacher() works Mon–Fri 07:00–15:00, so that
 * day's scan window is 06:00–16:00.
 */
class KioskCameraHealthTest extends TestCase
{
    private KioskDevice $kiosk;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->kiosk, $this->token] = KioskDevice::register('Main Entrance', 'tests');
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time));
    }

    private function report(string $camera): TestResponse
    {
        return $this->withCredentials()
            ->withCookie(KioskDevice::COOKIE, $this->token)
            ->postJson('/kiosk/health', ['camera' => $camera]);
    }

    private function scan(string $barcode, string $photo, string $faceDetected): TestResponse
    {
        return $this->withCredentials()
            ->withCookie(KioskDevice::COOKIE, $this->token)
            ->postJson('/kiosk/scan', ['barcode' => $barcode, 'photo' => $photo, 'face_detected' => $faceDetected]);
    }

    /** A webcam-sized JPEG: a covered lens (flat dark gray, as the real ones measured) or a normal varied picture. */
    private function photo(bool $covered): string
    {
        $image = imagecreatetruecolor(480, 360);

        if ($covered) {
            imagefill($image, 0, 0, imagecolorallocate($image, 27, 27, 27));
        } else {
            for ($x = 0; $x < 480; $x += 20) {
                $shade = 40 + (int) ($x / 480 * 180);
                imagefilledrectangle($image, $x, 0, $x + 19, 359, imagecolorallocate($image, $shade, $shade, (int) ($shade * 0.8)));
            }
        }

        ob_start();
        imagejpeg($image, null, 80);

        return 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());
    }

    private function seenAt(string $time): void
    {
        $this->kiosk->forceFill(['last_seen_at' => $time])->save();
    }

    public function test_a_camera_problem_opens_one_alert_that_closes_when_the_camera_works_again(): void
    {
        $this->at('2026-10-05 09:14:00');
        $this->report('blocked')->assertExactJson(['success' => true]);
        $this->at('2026-10-05 09:19:00');
        $this->report('blocked');

        $this->at('2026-10-05 09:40:00');
        $this->report('ok');

        $alert = KioskAlert::query()->sole();
        $this->assertSame(['blocked', '2026-10-05 09:14:00', '2026-10-05 09:40:00', 'fixed', null], [
            $alert->type, $alert->started_at->toDateTimeString(), $alert->resolved_at?->toDateTimeString(), $alert->resolution, $alert->open_device_id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'kiosk_alert.opened')->where('actor', 'kiosk: Main Entrance')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'kiosk_alert.closed')->count());
    }

    public function test_a_different_camera_problem_replaces_the_open_alert(): void
    {
        $this->at('2026-10-05 09:14:00');
        $this->report('blocked');
        $this->at('2026-10-05 09:20:00');

        $this->report('disconnected');

        $this->assertSame(['changed', null], KioskAlert::query()->where('type', 'blocked')->get()->map(fn ($a) => [$a->resolution, $a->open_device_id])->sole());
        $this->assertSame('disconnected', KioskAlert::query()->open()->sole()->type);
    }

    public function test_the_database_allows_only_one_open_alert_per_kiosk(): void
    {
        $this->report('blocked');

        $this->expectException(UniqueConstraintViolationException::class);

        KioskAlert::create(['kiosk_device_id' => $this->kiosk->id, 'type' => 'denied', 'started_at' => now(), 'open_device_id' => $this->kiosk->id]);
    }

    public function test_only_a_registered_kiosk_can_report_and_only_known_camera_states(): void
    {
        $notAKiosk = $this->postJson('/kiosk/health', ['camera' => 'blocked']);
        $unknownState = $this->report('broken');

        $unknownState->assertJsonValidationErrors('camera');
        $notAKiosk->assertForbidden();
        $this->assertSame(0, KioskAlert::query()->count());
    }

    #[TestWith(['superadmin', true])]
    #[TestWith(['admin', true])]
    #[TestWith(['principal', false])]
    public function test_super_admin_and_admin_see_open_alerts_on_the_dashboard_and_sidebar_but_the_principal_does_not(string $role, bool $alerted): void
    {
        $this->at('2026-10-05 09:14:00');
        $this->report('blocked');

        $response = $this->actingAs($this->account($role))->get(route("{$role}.dashboard"));

        if ($alerted) {
            $response->assertSee('<strong>Main Entrance:</strong> Kiosk camera blocked or too dark since 9:14 AM', false)
                ->assertSee('sidebar-kiosk-badge" title="Open kiosk alerts (see the dashboard)">1</span>', false);
        } else {
            $response->assertDontSee('kiosk-alert-banner', false)
                ->assertDontSee('Kiosk camera')
                ->assertDontSee('sidebar-kiosk-badge', false);
        }
    }

    public function test_alerts_contain_no_photo_urls(): void
    {
        $teacher = $this->teacher();
        $this->at('2026-10-05 06:58:00');
        $this->scan($teacher->barcode, $this->photo(covered: true), '');
        $photoId = DB::table('scan_photos')->value('id');
        $this->report('blocked');

        $dashboard = $this->actingAs($this->account('superadmin'))->get(route('superadmin.dashboard'));
        $settings = $this->get(route('superadmin.settings', ['tab' => 'kiosk']));

        $banner = Str::between($dashboard->getContent(), 'class="kiosk-alert-banner"', '</div>');
        $history = Str::between($settings->getContent(), 'Camera and Offline Alerts', '</table>');
        foreach ([$banner, $history] as $html) {
            $this->assertStringContainsString('Kiosk camera blocked or too dark', $html);
            $this->assertStringNotContainsString('scan-photos', $html);
            $this->assertStringNotContainsString(route('photos.scan', $photoId), $html);
            $this->assertStringNotContainsString('<img', $html);
        }
    }

    public function test_a_scan_with_a_blocked_camera_is_still_saved_and_flagged(): void
    {
        $teacher = $this->teacher();
        $this->at('2026-10-05 06:58:00');

        $response = $this->scan($teacher->barcode, $this->photo(covered: true), '1');

        $response->assertJson(['success' => true, 'title' => 'AM Arrival']);
        $this->assertSame('06:58:00', DB::table('attendance')->where('teacher_id', $teacher->id)->value('am_arrival'));
        $photo = DB::table('scan_photos')->sole();
        $this->assertNotNull($photo->path);
        $this->assertSame([1, null], [(int) $photo->camera_blocked, $photo->face_detected]);
    }

    public function test_a_clear_photo_with_no_face_found_carries_only_the_no_face_flag(): void
    {
        $teacher = $this->teacher();
        $this->at('2026-10-05 06:58:00');

        $this->scan($teacher->barcode, $this->photo(covered: false), '0')->assertJson(['success' => true]);

        $photo = DB::table('scan_photos')->sole();
        $this->assertSame([0, 0], [(int) $photo->camera_blocked, (int) $photo->face_detected]);
    }

    public function test_the_new_flags_show_on_scan_photos_and_in_the_principals_counts(): void
    {
        $ana = $this->teacher();
        $ben = $this->teacher();
        $this->at('2026-10-07 06:58:00');
        $this->scan($ana->barcode, $this->photo(covered: true), '');
        $this->at('2026-10-07 07:30:00');
        $this->scan($ben->barcode, $this->photo(covered: false), '0');

        $scanPhotos = $this->actingAs($this->account('superadmin'))->get(route('superadmin.scan-photos', ['date' => '2026-10-07']));
        $this->flushSession();
        $counts = $this->actingAs($this->account('principal'))->getJson(route('principal.scan-verification-counts'))->json();

        $scanPhotos->assertSee('Camera blocked')->assertSee('No face detected');
        $this->assertSame(['blocked_week' => 1, 'noface_week' => 1, 'flagged_week' => 2], [
            'blocked_week' => $counts['blocked_week'], 'noface_week' => $counts['noface_week'], 'flagged_week' => $counts['flagged_week'],
        ]);
    }

    public function test_the_offline_alert_opens_when_check_ins_stop_during_a_scan_window_and_closes_on_the_next_one(): void
    {
        $this->teacher();
        $this->at('2026-10-05 08:50:00');
        $this->report('blocked');

        $this->at('2026-10-05 08:58:00');
        $this->artisan('kiosk:check-offline')->expectsOutput('0 kiosk offline alert(s) opened.')->assertSuccessful();
        $this->at('2026-10-05 09:01:00');
        $this->artisan('kiosk:check-offline')->expectsOutput('1 kiosk offline alert(s) opened.')->assertSuccessful();
        $this->at('2026-10-05 09:02:00');
        $this->artisan('kiosk:check-offline')->expectsOutput('0 kiosk offline alert(s) opened.')->assertSuccessful();

        $offline = KioskAlert::query()->open()->sole();
        $this->assertSame(['offline', 'Kiosk offline since 8:50 AM'], [$offline->type, $offline->message()]);
        $this->assertSame('offline', KioskAlert::query()->where('type', 'blocked')->value('resolution'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'kiosk_alert.opened')->where('actor', 'system')->count());

        $this->at('2026-10-05 09:20:00');
        $this->report('ok');

        $this->assertSame(0, KioskAlert::query()->open()->count());
        $this->assertSame('online', $offline->fresh()->resolution);
    }

    /** @return array<string, array{0: string, 1: string, 2?: string}> */
    public static function noOfflineAlert(): array
    {
        return [
            'before the scan window' => ['2026-10-05 05:59:00', '2026-10-05 04:00:00'],
            'after the scan window' => ['2026-10-05 16:01:00', '2026-10-05 14:00:00'],
            'Sunday: nobody scheduled' => ['2026-10-04 10:00:00', '2026-10-04 08:00:00'],
            'whole-day school event' => ['2026-10-05 10:00:00', '2026-10-05 08:00:00', 'event'],
            'attendance recording off' => ['2026-10-05 10:00:00', '2026-10-05 08:00:00', 'recording off'],
            'kiosk not seen for 8 days' => ['2026-10-05 10:00:00', '2026-09-27 10:00:00'],
        ];
    }

    #[DataProvider('noOfflineAlert')]
    public function test_no_offline_alert_outside_a_scan_window_or_when_nobody_needs_the_kiosk(string $now, string $lastSeen, string $condition = ''): void
    {
        $this->teacher();
        $this->seenAt($lastSeen);
        if ($condition === 'event') {
            DB::table('school_events')->insert(['event_name' => 'Founding Anniversary', 'event_type' => 'Holiday', 'date_from' => '2026-10-05', 'date_to' => '2026-10-05']);
        }
        if ($condition === 'recording off') {
            DB::table('system_settings')->where('id', 1)->update(['attendance_recording_enabled' => false]);
        }
        $this->at($now);

        $this->artisan('kiosk:check-offline')->assertSuccessful();

        $this->assertSame(0, KioskAlert::query()->count());
    }

    public function test_a_whole_day_events_workers_still_need_the_kiosk(): void
    {
        $worker = $this->teacher();
        $this->teacher();
        $eventId = DB::table('school_events')->insertGetId(['event_name' => 'Enrollment', 'event_type' => 'Other', 'date_from' => '2026-10-05', 'date_to' => '2026-10-05']);
        DB::table('school_event_personnel')->insert(['school_event_id' => $eventId, 'teacher_id' => $worker->id]);
        $this->seenAt('2026-10-05 08:00:00');
        $this->at('2026-10-05 10:00:00');

        $this->artisan('kiosk:check-offline')->expectsOutput('1 kiosk offline alert(s) opened.');
    }

    public function test_an_overnight_shifts_scan_window_runs_past_midnight(): void
    {
        $this->teacher(in: '22:00:00', out: '06:00:00', days: ['Monday']);
        $this->seenAt('2026-10-06 05:00:00');

        $this->at('2026-10-06 07:30:00');
        $this->artisan('kiosk:check-offline')->expectsOutput('0 kiosk offline alert(s) opened.');
        $this->at('2026-10-06 06:30:00');
        $this->artisan('kiosk:check-offline')->expectsOutput('1 kiosk offline alert(s) opened.');

        $this->assertSame('2026-10-06 05:00:00', KioskAlert::query()->open()->sole()->started_at->toDateTimeString());
    }

    public function test_revoking_a_kiosk_closes_its_open_alert(): void
    {
        $this->report('denied');

        $this->actingAs($this->account('superadmin'))->post(route('superadmin.settings.kiosk.revoke'), ['id' => $this->kiosk->id]);

        $this->assertSame([0, 'revoked'], [KioskAlert::query()->open()->count(), KioskAlert::query()->value('resolution')]);
    }

    public function test_settings_lists_the_last_30_days_of_alerts(): void
    {
        $this->at('2026-09-01 08:00:00');
        $this->report('denied');
        $this->report('ok');
        $this->at('2026-10-05 09:14:00');
        $this->report('disconnected');

        $response = $this->actingAs($this->account('superadmin'))->get(route('superadmin.settings', ['tab' => 'kiosk']));

        $history = Str::between($response->getContent(), 'Camera and Offline Alerts', '</table>');
        $this->assertStringContainsString('Kiosk camera disconnected', $history);
        $this->assertStringContainsString('Still open', $history);
        $this->assertStringNotContainsString('permission denied', $history);
    }
}
