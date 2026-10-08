<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scan Photos page: the Super Admin's gallery of kiosk webcam photos.
 */
class ScanPhotosPageTest extends TestCase
{
    private function photo(array $attributes): int
    {
        return DB::table('scan_photos')->insertGetId($attributes + [
            'teacher_id' => null,
            'barcode' => null,
            'attendance_date' => '2026-10-05',
            'scanned_at' => '2026-10-05 07:00:00',
            'outcome' => 'accepted',
            'scan_kind' => 'IN',
            'path' => 'scan-photos/2026/10/05/'.uniqid().'.jpg',
        ]);
    }

    public function test_the_super_admin_sees_one_days_photos_newest_first(): void
    {
        $morning = $this->teacher(['fullname' => 'Ana Morning']);
        $noon = $this->teacher(['fullname' => 'Ben Noon']);

        $withPhoto = $this->photo(['teacher_id' => $morning->id, 'scanned_at' => '2026-10-05 06:55:00']);
        $this->photo(['teacher_id' => $noon->id, 'scanned_at' => '2026-10-05 12:20:00', 'scan_kind' => 'BREAK_OUT', 'path' => null]);
        $otherDay = $this->photo(['teacher_id' => $morning->id, 'attendance_date' => '2026-10-04', 'scanned_at' => '2026-10-04 06:50:00']);

        $this->actingAs($this->account('superadmin'))
            ->get(route('superadmin.scan-photos', ['date' => '2026-10-05']))
            ->assertOk()
            ->assertSeeInOrder(['No photo', 'Ben Noon</strong>', '12:20:00 PM', 'Lunch out', 'Ana Morning</strong>', '6:55:00 AM', 'Time in'], false)
            ->assertSee(route('photos.scan', $withPhoto))
            ->assertDontSee(route('photos.scan', $otherDay));
    }

    public function test_the_filters_narrow_by_person_and_outcome(): void
    {
        $ana = $this->teacher(['fullname' => 'Ana Accepted']);
        $dan = $this->teacher(['fullname' => 'Dan Duplicate']);

        $this->photo(['teacher_id' => $ana->id]);
        $this->photo(['teacher_id' => $dan->id, 'outcome' => 'duplicate', 'scan_kind' => null]);
        $this->photo(['barcode' => '999999', 'outcome' => 'not_found', 'scan_kind' => null]);

        $this->actingAs($this->account('superadmin'));

        $this->get(route('superadmin.scan-photos', ['date' => '2026-10-05', 'outcome' => 'not_accepted']))
            ->assertOk()
            ->assertSee('Dan Duplicate')
            ->assertSee('Unknown barcode 999999')
            ->assertDontSee('Ana Accepted</strong>', false);

        $this->get(route('superadmin.scan-photos', ['date' => '2026-10-05', 'teacher_id' => $ana->id]))
            ->assertOk()
            ->assertSee('Ana Accepted</strong>', false)
            ->assertDontSee('Dan Duplicate</strong>', false)
            ->assertDontSee('Unknown barcode 999999');
    }

    public function test_a_typed_scan_shows_the_manual_entry_chip(): void
    {
        $typed = $this->teacher(['fullname' => 'Tina Typed']);
        $scanned = $this->teacher(['fullname' => 'Sam Scanned']);
        $this->photo(['teacher_id' => $typed->id, 'scanned_at' => '2026-10-05 07:10:00', 'input_method' => 'typed']);
        $this->photo(['teacher_id' => $scanned->id, 'scanned_at' => '2026-10-05 07:00:00', 'input_method' => 'scanned']);

        $page = $this->actingAs($this->account('superadmin'))
            ->get(route('superadmin.scan-photos', ['date' => '2026-10-05']));

        $page->assertSeeInOrder(['Tina Typed</strong>', 'Manual entry', 'Sam Scanned</strong>'], false);
        $this->assertSame(1, substr_count($page->getContent(), 'class="flag-chip flag-manual"'));
    }

    public function test_accepted_scans_of_different_people_seconds_apart_on_one_kiosk_show_the_rapid_scan_chip(): void
    {
        $ana = $this->teacher(['fullname' => 'Ana Proxy']);
        $ben = $this->teacher(['fullname' => 'Ben Proxy']);
        $cy = $this->teacher(['fullname' => 'Cy Later']);
        [$kiosk] = KioskDevice::register('Main gate', 'tests');
        $this->photo(['teacher_id' => $ana->id, 'scanned_at' => '2026-10-05 07:00:00', 'kiosk_device_id' => $kiosk->id]);
        $this->photo(['teacher_id' => $ben->id, 'scanned_at' => '2026-10-05 07:00:03', 'kiosk_device_id' => $kiosk->id]);
        $this->photo(['teacher_id' => $cy->id, 'scanned_at' => '2026-10-05 07:05:00', 'kiosk_device_id' => $kiosk->id]);

        $page = $this->actingAs($this->account('superadmin'))
            ->get(route('superadmin.scan-photos', ['date' => '2026-10-05']));

        $page->assertSeeInOrder(['Cy Later</strong>', 'Ben Proxy</strong>', 'Rapid scan', 'Ana Proxy</strong>', 'Rapid scan'], false);
        $this->assertSame(2, substr_count($page->getContent(), 'class="flag-chip flag-rapid"'));
    }

    public function test_only_the_super_admin_can_open_it(): void
    {
        $this->account('superadmin');

        $this->get(route('superadmin.scan-photos'))->assertRedirect('/login');

        $this->actingAs($this->account('admin'))->get(route('superadmin.scan-photos'))->assertRedirect(route('admin.dashboard'));
        $this->flushSession();
        $this->actingAs($this->account('principal'))->get(route('superadmin.scan-photos'))->assertRedirect(route('principal.dashboard'));
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $this->actingAs($this->account('superadmin'))
            ->get(route('superadmin.scan-photos', ['date' => '05/10/2026']))
            ->assertSessionHasErrors('date');
    }
}
