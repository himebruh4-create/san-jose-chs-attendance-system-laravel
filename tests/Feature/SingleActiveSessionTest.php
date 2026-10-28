<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\KioskDevice;
use App\Support\ActiveSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * One device per account (App\Support\ActiveSessions). Each "device" is a
 * browser with its own session data, address and User-Agent; the test
 * swaps them in and out of the one test session.
 */
class SingleActiveSessionTest extends TestCase
{
    private const DEVICES = [
        'A' => ['ip' => '10.0.0.1', 'agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'],
        'B' => ['ip' => '10.0.0.2', 'agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'],
        'C' => ['ip' => '10.0.0.3', 'agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'],
    ];

    /** @var array<string, array<string, mixed>> Session data of the devices not in use. */
    private array $sessions = [];

    private ?string $device = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The login page needs a finished setup (a Super Admin).
        $this->account('superadmin');
        $this->travelTo('2026-10-28 09:15:00');
    }

    /** Switches to a device's browser (its own session, address and User-Agent). */
    private function on(string $device): static
    {
        if ($this->device !== null) {
            $this->sessions[$this->device] = session()->all();
        }

        session()->flush();
        session()->put($this->sessions[$device] ?? []);
        $this->app['auth']->forgetGuards();
        $this->device = $device;

        return $this->withHeader('User-Agent', self::DEVICES[$device]['agent'])
            ->withServerVariables(['REMOTE_ADDR' => self::DEVICES[$device]['ip']]);
    }

    private function logIn(string $device, Account $account): TestResponse
    {
        return $this->on($device)->post('/login', ['email' => $account->email, 'password' => 'Secret#2026'])
            ->assertRedirect(route($account->homeRoute()));
    }

    private function auditCount(string $action): int
    {
        return DB::table('audit_logs')->where('action', $action)->count();
    }

    #[TestWith(['superadmin'])]
    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_a_login_on_a_second_device_prompts_the_first(string $role): void
    {
        $account = $this->account($role);
        $this->logIn('A', $account);
        $this->logIn('B', $account);

        $this->on('B')->getJson('/session/status')->assertExactJson(['success' => true, 'status' => 'active']);

        $this->on('A')->getJson('/session/status')->assertExactJson([
            'success' => true,
            'status' => 'replaced',
            'message' => 'Someone signed in to this account on Chrome on Windows (10.0.0.2) at 9:15 AM. For security, only one device can use this account at a time.',
            'seconds_left' => 30,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'session.signed_in_elsewhere',
            'details->ip' => '10.0.0.2',
            'details->browser' => 'Chrome',
            'details->os' => 'Windows',
        ]);
    }

    public function test_a_replaced_device_cannot_load_pages_or_data_until_it_answers(): void
    {
        $account = $this->account('admin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);

        $this->on('A')->get('/admin/dashboard')
            ->assertStatus(409)
            ->assertViewIs('auth.session-replaced')
            ->assertSee('Your account was signed in on another device');
        $this->on('A')->getJson('/data/school-events')
            ->assertStatus(409)
            ->assertJson(['success' => false, 'session_replaced' => true]);
    }

    public function test_stay_logged_in_keeps_the_first_device_and_signs_out_the_second_without_a_prompt(): void
    {
        $account = $this->account('superadmin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);
        $this->on('A')->getJson('/session/status');

        $this->on('A')->postJson('/session/stay')->assertExactJson(['success' => true, 'status' => 'active']);

        $this->on('A')->get('/superadmin/dashboard')->assertOk();
        $this->on('B')->get('/superadmin/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHas('error', ActiveSessions::OTHER_DEVICE_NOTICE);
        $this->on('B')->get('/superadmin/dashboard')->assertRedirect('/login');
        $this->assertSame(1, $this->auditCount('session.stayed'));
        $this->assertSame(1, $this->auditCount('session.logged_out_other_device'));
    }

    public function test_a_signed_out_device_gets_a_401_from_data_endpoints(): void
    {
        $account = $this->account('superadmin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);
        $this->on('A')->postJson('/session/stay');

        $this->on('B')->getJson('/data/review-summary')
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'session_ended' => true, 'redirect' => route('login'), 'message' => ActiveSessions::OTHER_DEVICE_NOTICE]);
        $this->on('B')->postJson('/session/stay')->assertUnauthorized();
    }

    public function test_log_out_on_the_prompt_signs_out_only_the_first_device(): void
    {
        $account = $this->account('principal');
        $this->logIn('A', $account);
        $this->logIn('B', $account);

        $this->on('A')->post('/logout')->assertRedirect('/login');

        $this->on('A')->get('/principal/dashboard')->assertRedirect('/login');
        $this->on('B')->get('/principal/dashboard')->assertOk();
    }

    public function test_the_countdown_ending_signs_out_the_first_device(): void
    {
        $account = $this->account('admin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);
        $this->on('A')->getJson('/session/status');
        $this->travel(30)->seconds();

        $this->on('A')->postJson('/session/timeout')->assertUnauthorized()->assertJson(['session_ended' => true]);

        $this->on('A')->get('/admin/dashboard')->assertRedirect('/login');
        $this->on('B')->get('/admin/dashboard')->assertOk();
        $this->assertSame(1, $this->auditCount('session.logged_out_no_response'));
    }

    public function test_a_prompt_left_unanswered_is_signed_out_by_the_server(): void
    {
        $account = $this->account('admin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);
        $this->on('A')->getJson('/session/status');
        $this->travel(36)->seconds();

        $this->on('A')->postJson('/session/stay')->assertUnauthorized();

        $this->on('B')->get('/admin/dashboard')->assertOk();
        $this->assertSame(1, $this->auditCount('session.logged_out_no_response'));
        $this->assertSame(0, $this->auditCount('session.stayed'));
    }

    public function test_a_third_login_leaves_only_the_latest_replaced_device_able_to_stay(): void
    {
        $account = $this->account('admin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);
        $this->logIn('C', $account);

        $this->on('A')->getJson('/session/status')->assertUnauthorized();
        $this->on('B')->getJson('/session/status')->assertJson(['status' => 'replaced']);
    }

    public function test_after_a_normal_logout_the_next_login_shows_no_prompt(): void
    {
        $account = $this->account('superadmin');
        $this->logIn('A', $account);
        $this->on('A')->post('/logout');

        $this->logIn('B', $account);

        $this->on('B')->getJson('/session/status')->assertJson(['status' => 'active']);
        $this->assertSame(0, $this->auditCount('session.signed_in_elsewhere'));
    }

    public function test_two_accounts_with_the_same_role_do_not_affect_each_other(): void
    {
        $this->logIn('A', $this->account('admin'));
        $this->logIn('B', $this->account('admin'));

        $this->on('A')->getJson('/session/status')->assertJson(['status' => 'active']);
        $this->on('B')->getJson('/session/status')->assertJson(['status' => 'active']);
        $this->assertSame(0, $this->auditCount('session.signed_in_elsewhere'));
    }

    public function test_a_session_without_its_own_login_cannot_join_while_another_device_is_active(): void
    {
        // As a "remember me" cookie would sign a browser back in.
        $account = $this->account('admin');
        $this->logIn('A', $account);

        $this->on('B')->actingAs($account)->get('/admin/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHas('error', ActiveSessions::OTHER_DEVICE_NOTICE);
        $this->on('A')->get('/admin/dashboard')->assertOk();
    }

    public function test_the_ten_second_check_does_not_keep_an_idle_session_signed_in(): void
    {
        $account = $this->account('admin');
        $this->logIn('A', $account);

        $this->travel(100)->minutes();
        $this->on('A')->getJson('/session/status')->assertJson(['status' => 'active']);
        $this->travel(21)->minutes();

        $this->on('A')->getJson('/session/status')->assertUnauthorized()->assertJson(['session_ended' => true]);
    }

    public function test_the_kiosk_keeps_scanning_from_a_replaced_browser(): void
    {
        Storage::fake('local');
        $kioskToken = $this->kioskToken();
        $teacher = $this->teacher();
        $account = $this->account('admin');
        $this->logIn('A', $account);
        $this->logIn('B', $account);

        $this->on('A')->withCredentials()->withCookie(KioskDevice::COOKIE, $kioskToken)
            ->postJson('/kiosk/scan', ['barcode' => $teacher->barcode])
            ->assertOk()
            ->assertJson(['success' => true, 'title' => 'AM Arrival']);
    }
}
