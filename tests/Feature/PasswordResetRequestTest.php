<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Support\PasswordResetRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * My Account -> Forgot Password -> Request new password (Admin and
 * Principal): a request the Super Admin sees on the dashboard and resolves.
 * The password is never changed by it.
 */
class PasswordResetRequestTest extends TestCase
{
    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_my_account_offers_admin_and_principal_a_request_new_password_button(string $role): void
    {
        $this->actingAs($this->account($role))->get(route('account'))
            ->assertSee('Forgot Password?')
            ->assertSee('Request new password')
            ->assertSee('action="'.route('account.password-reset-request').'"', false);
    }

    public function test_the_super_admin_has_no_request_button_and_cannot_file_one(): void
    {
        $superAdmin = $this->account('superadmin');

        $this->actingAs($superAdmin)->get(route('account'))->assertDontSee('Request new password');
        $this->post(route('account.password-reset-request'))->assertForbidden();

        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_a_request_is_sent_to_the_super_admin_without_changing_the_password(): void
    {
        $admin = $this->account('admin', ['full_name' => 'Ana Officer', 'email' => 'ana.officer@test.local']);
        $passwordHash = $admin->password;

        $this->actingAs($admin)->from(route('account'))->post(route('account.password-reset-request'))
            ->assertRedirect(route('account'))
            ->assertSessionHas('success', 'Your request was sent to the Super Admin.');

        $this->assertDatabaseHas('password_reset_requests', ['account_id' => $admin->id, 'status' => 'pending']);
        $this->assertSame($passwordHash, Account::find($admin->id)->password);
        $this->assertTrue(Hash::check('Secret#2026', Account::find($admin->id)->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset.requested', 'subject_id' => $admin->id]);

        $this->get(route('account'))->assertSee('Your request was sent to the Super Admin.')->assertDontSee('Request new password');

        $this->flushSession();
        $this->actingAs($this->account('superadmin'))->get(route('superadmin.dashboard'))
            ->assertSee('Password Reset Request')
            ->assertSee('<strong>Ana Officer</strong> (Admin) has requested a password reset.', false);
    }

    public function test_a_second_request_while_one_is_pending_files_nothing_and_says_the_same(): void
    {
        $principal = $this->account('principal');
        $this->actingAs($principal);

        $this->post(route('account.password-reset-request'))->assertSessionHas('success', PasswordResetRequests::SENT);
        $this->post(route('account.password-reset-request'))->assertSessionHas('success', PasswordResetRequests::SENT);

        $this->assertSame(1, DB::table('password_reset_requests')->where('account_id', $principal->id)->count());
    }

    public function test_after_the_super_admin_resolves_it_a_new_request_can_be_filed(): void
    {
        $admin = $this->account('admin');
        DB::table('password_reset_requests')->insert(['account_id' => $admin->id, 'status' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($admin)->post(route('account.password-reset-request'));

        $this->assertSame(1, DB::table('password_reset_requests')->where('account_id', $admin->id)->where('status', 'pending')->count());
    }

    public function test_the_signed_out_forgot_password_files_the_same_kind_of_request(): void
    {
        $this->account('superadmin');   // setup is done
        $admin = $this->account('admin', ['email' => 'officer@test.local']);

        $this->post('/forgot-password', ['email' => 'officer@test.local'])->assertOk();
        $this->post('/forgot-password', ['email' => 'nobody@test.local'])->assertOk();

        $this->assertSame([$admin->id], DB::table('password_reset_requests')->where('status', 'pending')->pluck('account_id')->all());
    }

    public function test_a_guest_cannot_file_a_request_from_my_account(): void
    {
        $this->post(route('account.password-reset-request'))->assertRedirect(route('login'));

        $this->assertDatabaseCount('password_reset_requests', 0);
    }
}
