<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The Principal's read-only Scan Verification card: counts only, from one
 * endpoint no other role can read.
 */
class PrincipalScanVerificationTest extends TestCase
{
    private const ENDPOINT = '/principal/dashboard/scan-verification';

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday; the week starts Monday 2026-10-05 00:00 (Asia/Manila).
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00', 'Asia/Manila'));
    }

    private function scan(string $scannedAt, string $outcome, bool $withPhoto = true, string $inputMethod = 'scanned', ?int $teacherId = null, ?int $kioskId = null): void
    {
        DB::table('scan_photos')->insert([
            'teacher_id' => $teacherId,
            'kiosk_device_id' => $kioskId,
            'attendance_date' => substr($scannedAt, 0, 10),
            'scanned_at' => $scannedAt,
            'outcome' => $outcome,
            'input_method' => $inputMethod,
            'path' => $withPhoto ? 'scan-photos/x/'.uniqid().'.jpg' : null,
        ]);
    }

    private function rejectedScan(int $teacherId, bool $reviewed = false): int
    {
        return DB::table('attendance_rejected_scans')->insertGetId([
            'teacher_id' => $teacherId,
            'attempted_at' => '2026-10-06 07:01:00',
            'attendance_date' => '2026-10-06',
            'reason' => 'DUPLICATE_SCAN',
            'reviewed_at' => $reviewed ? '2026-10-06 09:00:00' : null,
        ]);
    }

    public function test_the_principal_gets_counts_for_this_week_only(): void
    {
        $teacher = $this->teacher(['fullname' => 'Secret Name']);

        $this->scan('2026-10-05 00:00:00', 'accepted');                   // not flagged (Monday 00:00 is in)
        $this->scan('2026-10-05 07:00:00', 'duplicate', withPhoto: false); // repeat + no photo: counts once in the total
        $this->scan('2026-10-06 07:00:00', 'complete');                   // repeat
        $this->scan('2026-10-06 07:05:00', 'not_found');                  // unknown barcode
        $this->scan('2026-10-07 07:00:00', 'accepted', withPhoto: false); // no photo
        $this->scan('2026-10-07 07:10:00', 'recording_disabled');         // not flagged
        $this->scan('2026-10-07 07:20:00', 'accepted', inputMethod: 'typed');  // manual entry
        $this->scan('2026-10-07 07:30:00', 'duplicate', inputMethod: 'typed'); // repeat + manual entry: counts once in the total
        $this->scan('2026-10-04 23:00:00', 'accepted', inputMethod: 'typed');  // last week: excluded
        $this->scan('2026-10-04 23:59:59', 'duplicate', withPhoto: false); // last week (Sunday): excluded
        $this->rejectedScan($teacher->id);
        $this->rejectedScan($teacher->id);
        $this->rejectedScan($teacher->id, reviewed: true);

        $response = $this->actingAs($this->account('principal'))->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame([
            'flagged_week' => 6,
            'repeat_week' => 3,
            'rapid_week' => 0,
            'unknown_week' => 1,
            'nophoto_week' => 2,
            'manual_week' => 2,
            'unreviewed' => 2,
        ], array_diff_key($response->json(), ['loaded_at' => true]));
        $this->assertSame('2026-10-07T10:00:00+08:00', $response->json('loaded_at'));
        $response->assertDontSee('Secret Name')->assertDontSee('scan-photos');
    }

    public function test_the_dashboard_shows_the_counts_without_names_photos_or_links(): void
    {
        $teacher = $this->teacher(['fullname' => 'Secret Name']);
        $this->scan('2026-10-06 07:00:00', 'duplicate', withPhoto: false);
        $this->rejectedScan($teacher->id);

        $this->actingAs($this->account('principal'))->get(route('principal.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Scan Verification', 'Flagged scans this week', 'Repeat scan', 'Rapid scan', 'Unknown barcode', 'No photo', 'Manual entry',
                'A scan can have more than one flag.', 'Waiting for review', 'Photos are reviewed by the Super Admin.'])
            ->assertSee('class="sv-line sv-amber"', false)
            ->assertDontSee('scan-photos')
            ->assertDontSee(route('superadmin.scan-photos'));
    }

    public function test_an_empty_scan_photos_table_shows_the_empty_state(): void
    {
        $this->actingAs($this->account('principal'));

        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJson(['flagged_week' => 0, 'repeat_week' => 0, 'rapid_week' => 0, 'unknown_week' => 0, 'nophoto_week' => 0, 'manual_week' => 0, 'unreviewed' => 0]);

        $page = $this->get(route('principal.dashboard'))->assertOk()
            ->assertDontSee('class="sv-line sv-amber"', false)
            ->getContent();

        // The empty note is shown and the flag breakdown hidden.
        $this->assertMatchesRegularExpression('/data-sv-empty\s*>No flagged scans this week</', $page);
        $this->assertMatchesRegularExpression('/data-sv-flagged-block\s+hidden/', $page);
    }

    #[TestWith([3, 2])]
    #[TestWith([5, 2])]
    #[TestWith([6, 0])]
    public function test_accepted_scans_of_different_people_on_one_kiosk_within_five_seconds_are_both_rapid(int $secondsApart, int $expectedRapid): void
    {
        $ana = $this->teacher();
        $ben = $this->teacher();
        [$kiosk] = KioskDevice::register('Main gate', 'tests');
        $this->scan('2026-10-07 07:00:00', 'accepted', teacherId: $ana->id, kioskId: $kiosk->id);
        $this->scan('2026-10-07 07:00:'.sprintf('%02d', $secondsApart), 'accepted', inputMethod: 'typed', teacherId: $ben->id, kioskId: $kiosk->id);

        $counts = $this->actingAs($this->account('principal'))->getJson(self::ENDPOINT)->json();

        $this->assertSame($expectedRapid, $counts['rapid_week']);
        // Ben's typed scan is counted once in the total even when it is also rapid.
        $this->assertSame(max($expectedRapid, 1), $counts['flagged_week']);
    }

    public function test_accepted_scans_of_different_people_on_different_kiosks_are_not_rapid(): void
    {
        $ana = $this->teacher();
        $ben = $this->teacher();
        [$mainGate] = KioskDevice::register('Main gate', 'tests');
        [$backGate] = KioskDevice::register('Back gate', 'tests');
        $this->scan('2026-10-07 07:00:00', 'accepted', teacherId: $ana->id, kioskId: $mainGate->id);
        $this->scan('2026-10-07 07:00:03', 'accepted', teacherId: $ben->id, kioskId: $backGate->id);

        $this->actingAs($this->account('principal'))->getJson(self::ENDPOINT)
            ->assertJson(['rapid_week' => 0, 'flagged_week' => 0]);
    }

    public function test_two_accepted_scans_of_the_same_person_are_not_rapid(): void
    {
        $ana = $this->teacher();
        [$kiosk] = KioskDevice::register('Main gate', 'tests');
        $this->scan('2026-10-07 07:00:00', 'accepted', teacherId: $ana->id, kioskId: $kiosk->id);
        $this->scan('2026-10-07 07:00:03', 'accepted', teacherId: $ana->id, kioskId: $kiosk->id);

        $this->actingAs($this->account('principal'))->getJson(self::ENDPOINT)
            ->assertJson(['rapid_week' => 0, 'flagged_week' => 0]);
    }

    public function test_rejected_scans_are_not_counted_as_rapid(): void
    {
        $ana = $this->teacher();
        $ben = $this->teacher();
        $cy = $this->teacher();
        [$kiosk] = KioskDevice::register('Main gate', 'tests');
        $this->scan('2026-10-07 07:00:00', 'accepted', teacherId: $ana->id, kioskId: $kiosk->id);
        $this->scan('2026-10-07 07:00:02', 'duplicate', teacherId: $ben->id, kioskId: $kiosk->id);
        $this->scan('2026-10-07 07:00:03', 'complete', teacherId: $cy->id, kioskId: $kiosk->id);
        $this->scan('2026-10-07 07:00:04', 'not_found', kioskId: $kiosk->id);

        $this->actingAs($this->account('principal'))->getJson(self::ENDPOINT)
            ->assertJson(['rapid_week' => 0, 'repeat_week' => 2, 'unknown_week' => 1, 'flagged_week' => 3]);
    }

    public function test_no_other_role_can_read_the_counts(): void
    {
        $this->account('superadmin');

        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $this->actingAs($this->account('admin'))->getJson(self::ENDPOINT)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->account('superadmin'))->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_waiting_for_review_drops_once_the_super_admin_reviews_a_scan(): void
    {
        $teacher = $this->teacher();
        $first = $this->rejectedScan($teacher->id);
        $this->rejectedScan($teacher->id);
        $principal = $this->account('principal');

        $this->actingAs($principal)->getJson(self::ENDPOINT)->assertJson(['unreviewed' => 2]);

        $this->flushSession();
        $this->actingAs($this->account('superadmin'))->postJson('/data/rejected-scans/review', ['id' => $first])
            ->assertJson(['success' => true]);

        $this->flushSession();
        $this->actingAs($principal)->getJson(self::ENDPOINT)->assertJson(['unreviewed' => 1]);
    }
}
