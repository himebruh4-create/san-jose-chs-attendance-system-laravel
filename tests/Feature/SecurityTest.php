<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The access-control gaps found in the native-PHP review, each pinned down.
 */
class SecurityTest extends TestCase
{
    public function test_guests_are_sent_to_login_from_every_role_page(): void
    {
        $this->account('superadmin');

        foreach (['/superadmin/dashboard', '/superadmin/personnel', '/admin/dashboard', '/admin/attendance-report',
            '/principal/dashboard', '/principal/monitoring', '/my-account', '/reports/monthly-summary'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_guests_get_json_401_from_data_endpoints(): void
    {
        foreach (['/data/review-summary', '/data/teacher-leaves?teacher_id=1', '/data/school-events', '/data/backups'] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }

    public function test_roles_cannot_open_each_others_pages_or_data(): void
    {
        $admin = $this->account('admin');
        $principal = $this->account('principal');

        $this->actingAs($admin)->get('/superadmin/settings')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)->getJson('/data/backups')->assertForbidden()->assertJson(['success' => false]);
        $this->actingAs($admin)->postJson('/data/adjustments', [])->assertForbidden();

        $this->flushSession();
        $this->actingAs($principal)->get('/admin/attendance-report')->assertRedirect(route('principal.dashboard'));
        // The Principal never had the DTR data endpoints.
        $this->actingAs($principal)->getJson('/data/teacher-schedule?teacher_id=1')->assertForbidden();
        $this->actingAs($principal)->postJson('/data/confirm-absent', [])->assertForbidden();
    }

    public function test_admin_monthly_report_needs_a_login(): void
    {
        // Was readable without logging in (admin/admin-monthly-report.php).
        $this->account('superadmin');
        $this->get('/admin/attendance-report')->assertRedirect('/login');
    }

    public function test_the_barcode_lookup_endpoint_is_gone(): void
    {
        // get-teacher-barcode.php handed out any person's barcode to anyone.
        $this->get('/get-teacher-barcode.php?id=1')->assertNotFound();
        $this->get('/teacher-crud.php')->assertNotFound();
    }

    public function test_login_regenerates_the_session_and_records_the_login(): void
    {
        $this->account('superadmin');
        $account = $this->account('admin', ['email' => 'staff@test.local']);

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => 'staff@test.local', 'password' => 'Secret#2026'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull($account->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'account_id' => $account->id]);
    }

    public function test_login_locks_out_after_five_failures(): void
    {
        $this->account('superadmin');
        $this->account('admin', ['email' => 'staff@test.local']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'staff@test.local', 'password' => 'wrong'])
                ->assertSessionHas('error', 'Invalid email or password!');
        }

        // Even the right password is refused during the lockout.
        $this->post('/login', ['email' => 'staff@test.local', 'password' => 'Secret#2026'])
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Too many failed attempts'));
        $this->assertGuest();
    }

    public function test_an_archived_account_is_signed_out_on_its_next_request(): void
    {
        $admin = $this->account('admin');

        $this->actingAs($admin)->get('/admin/personnel')->assertOk();

        $admin->forceFill(['is_deleted' => true])->save();

        $this->actingAs($admin)->get('/admin/personnel')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_setup_is_only_available_until_a_super_admin_exists_and_only_locally(): void
    {
        Cache::flush();

        $this->get('/login')->assertRedirect('/setup');
        $this->get('/setup', ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])->get('/setup')->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->post('/setup', [
            'full_name' => 'Head Admin',
            'email' => 'head@test.local',
            'password' => 'LongPass#1',
            'password_confirmation' => 'LongPass#1',
            'security_question' => 'First school?',
            'security_answer' => 'San Jose',
        ])->assertRedirect(route('superadmin.dashboard'));

        $this->assertDatabaseHas('accounts', ['email' => 'head@test.local', 'role' => 'superadmin']);

        auth()->logout();
        $this->get('/setup')->assertRedirect('/login');
    }

    public function test_the_kiosk_requires_a_registered_device(): void
    {
        $teacher = $this->teacher();

        $this->get('/kiosk')->assertForbidden()->assertSee('not a kiosk');
        $this->postJson('/kiosk/scan', ['barcode' => $teacher->barcode])->assertForbidden();
        $this->assertDatabaseCount('attendance', 0);

        $token = $this->kioskToken();
        $this->withCookie(KioskDevice::COOKIE, $token)->get('/kiosk')->assertOk();

        // A revoked kiosk stops working at once.
        KioskDevice::query()->update(['revoked_at' => now()]);
        $this->withCredentials()->withCookie(KioskDevice::COOKIE, $token)->postJson('/kiosk/scan', ['barcode' => $teacher->barcode])->assertForbidden();
    }

    public function test_the_kiosk_dtr_only_shows_the_person_who_just_scanned(): void
    {
        $a = $this->teacher();
        $b = $this->teacher();
        $token = $this->kioskToken();

        $this->withCredentials()->withCookie(KioskDevice::COOKIE, $token)->postJson('/kiosk/scan', ['barcode' => $a->barcode])->assertJson(['success' => true]);

        $this->withCredentials()->withCookie(KioskDevice::COOKIE, $token)->getJson('/kiosk/dtr?teacher_id='.$a->id)
            ->assertJsonPath('fullname', $a->fullname);

        $this->withCredentials()->withCookie(KioskDevice::COOKIE, $token)->getJson('/kiosk/dtr?teacher_id='.$b->id)
            ->assertJsonMissingPath('fullname')
            ->assertJsonPath('error', 'Scan your ID first to view your own DTR.');
    }

    public function test_responses_carry_security_headers(): void
    {
        $this->account('superadmin');

        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_personnel_photos_are_not_public(): void
    {
        $this->get('/photos/teacher_x.jpg')->assertForbidden();
        $this->get('/uploads/teachers/teacher_x.jpg')->assertNotFound();
    }
}
