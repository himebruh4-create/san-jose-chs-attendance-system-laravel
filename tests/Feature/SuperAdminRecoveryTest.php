<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Support\RecoveryCodes;
use App\Support\SecurityQuestions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Super Admin account recovery: 3 security questions, one-time recovery
 * codes, the limits they share, the after-login warning, the forced
 * question setup, and the server reset command.
 */
class SuperAdminRecoveryTest extends TestCase
{
    /** The test Super Admin's answers, typed differently (normalizing makes them match). */
    private const ANSWERS_AS_TYPED = ['juan  dela cruz', 'STO NINO street', 'eraserheads-1997'];

    private function forgotEmail(string $email): TestResponse
    {
        return $this->post(route('password.forgot'), ['step' => 'email', 'email' => $email]);
    }

    private function answer(array $answers): TestResponse
    {
        return $this->post(route('password.forgot'), ['step' => 'questions', 'answers' => $answers]);
    }

    private function useCode(string $email, string $code): TestResponse
    {
        return $this->post(route('password.forgot'), ['step' => 'code', 'email' => $email, 'recovery_code' => $code]);
    }

    private function resetTo(string $password): TestResponse
    {
        return $this->post(route('password.forgot'), ['step' => 'reset', 'new_password' => $password, 'confirm_password' => $password]);
    }

    private function failAnswers(Account $sa, int $times): void
    {
        $this->forgotEmail($sa->email);

        for ($i = 0; $i < $times; $i++) {
            $this->answer(['juan dela cruz', 'sto nino street', 'wrong answer']);
        }
    }

    private function auditCount(string $action, ?int $accountId = null): int
    {
        return DB::table('audit_logs')->where('action', $action)
            ->when($accountId, fn ($q) => $q->where('subject_id', $accountId))->count();
    }

    // ---------------------------------------------------------------- questions

    public function test_all_three_answers_unlock_the_reset_whatever_their_case_spaces_or_accents(): void
    {
        $sa = $this->account('superadmin');

        $questionsPage = $this->forgotEmail($sa->email);
        $resetPage = $this->answer(self::ANSWERS_AS_TYPED);
        $this->resetTo('Brand#New2026')->assertSee('Password reset successfully.');

        $questionsPage->assertSeeInOrder(array_map(fn ($key) => SecurityQuestions::LIST[$key], self::SECURITY_QUESTIONS));
        $resetPage->assertSee('name="new_password"', false);
        $this->assertTrue(Hash::check('Brand#New2026', $sa->fresh()->password));
        $this->assertTrue(SecurityQuestions::hasAll($sa->id), 'Answering the questions keeps them.');
    }

    public function test_one_wrong_answer_fails_without_saying_which(): void
    {
        $sa = $this->account('superadmin');
        $this->forgotEmail($sa->email);

        $response = $this->answer(['juan dela cruz', 'sto nino street', 'wrong answer']);

        $response->assertSee("Your answers don't match.")
            ->assertDontSee('name="new_password"', false);
        $this->assertSame(1, $this->auditCount('recovery.failed', $sa->id));
        $this->resetTo('Brand#New2026')->assertSee('Your session expired.');
        $this->assertTrue(Hash::check('Secret#2026', $sa->fresh()->password));
    }

    public function test_five_failures_lock_for_fifteen_minutes(): void
    {
        $sa = $this->account('superadmin');
        $this->failAnswers($sa, 5);

        $locked = $this->answer(self::ANSWERS_AS_TYPED);
        $this->travel(16)->minutes();
        $unlocked = $this->answer(self::ANSWERS_AS_TYPED);

        $locked->assertSee('Too many failed attempts. Try again in 15 minute(s).')->assertDontSee('name="new_password"', false);
        $unlocked->assertSee('name="new_password"', false);
        $this->assertSame(5, $this->auditCount('recovery.failed', $sa->id));
        $this->assertSame(1, $this->auditCount('recovery.lockout', $sa->id));
    }

    public function test_a_third_lockout_within_24_hours_disables_the_questions(): void
    {
        $sa = $this->account('superadmin');
        foreach ([3, 10] as $hoursAgo) {
            DB::table('audit_logs')->insert(['actor' => $sa->email, 'action' => 'recovery.lockout', 'subject_type' => 'account', 'subject_id' => $sa->id, 'created_at' => now()->subHours($hoursAgo)]);
        }

        $this->failAnswers($sa, 5);
        $this->travel(16)->minutes();
        $afterLockout = $this->forgotEmail($sa->email);
        $correctAnswers = $this->answer(self::ANSWERS_AS_TYPED);

        $this->assertNotNull($sa->fresh()->security_questions_disabled_at);
        $this->assertSame(1, $this->auditCount('recovery.questions_disabled', $sa->id));
        $afterLockout->assertSee('Security questions are turned off for this account')->assertSee('name="recovery_code"', false);
        $correctAnswers->assertDontSee('name="new_password"', false);
    }

    public function test_lockouts_older_than_24_hours_do_not_count(): void
    {
        $sa = $this->account('superadmin');
        foreach ([25, 30] as $hoursAgo) {
            DB::table('audit_logs')->insert(['actor' => $sa->email, 'action' => 'recovery.lockout', 'subject_type' => 'account', 'subject_id' => $sa->id, 'created_at' => now()->subHours($hoursAgo)]);
        }

        $this->failAnswers($sa, 5);

        $this->assertNull($sa->fresh()->security_questions_disabled_at);
    }

    public function test_failed_tries_from_one_computer_are_limited_across_accounts(): void
    {
        $sa = $this->account('superadmin');
        [$code] = RecoveryCodes::generate($sa->id);

        for ($i = 0; $i < 20; $i++) {
            $this->useCode("nobody{$i}@test.local", 'AAAAA-AAAAA');
        }
        $response = $this->useCode($sa->email, $code);

        $response->assertSee('Too many failed attempts from this computer.')->assertDontSee('name="new_password"', false);
        $this->assertSame(1, $this->auditCount('recovery.ip_limited'));
        $this->assertSame(RecoveryCodes::COUNT, RecoveryCodes::remaining($sa->id));
    }

    public function test_an_admin_email_still_files_a_reset_request(): void
    {
        $this->account('superadmin');
        $admin = $this->account('admin');

        $response = $this->forgotEmail($admin->email);

        $response->assertSee('Password Reset Request Sent.')->assertDontSee('name="answers[]"', false);
        $this->assertDatabaseHas('password_reset_requests', ['account_id' => $admin->id, 'status' => 'pending']);
    }

    // ---------------------------------------------------------------- recovery codes

    public function test_a_recovery_code_works_once_and_requires_new_questions(): void
    {
        $sa = $this->account('superadmin');
        $codes = RecoveryCodes::generate($sa->id);

        $this->useCode($sa->email, strtolower(str_replace('-', ' ', $codes[0])))->assertSee('name="new_password"', false);
        $this->resetTo('Brand#New2026')->assertSee('you will be asked to set 3 new security questions');
        $reused = $this->useCode($sa->email, $codes[0]);

        $reused->assertSee('That recovery code is not valid for this email.');
        $this->assertTrue(Hash::check('Brand#New2026', $sa->fresh()->password));
        $this->assertFalse(SecurityQuestions::hasAll($sa->id));
        $this->assertSame(RecoveryCodes::COUNT - 1, RecoveryCodes::remaining($sa->id));
        $this->assertSame(1, $this->auditCount('recovery_code.used', $sa->id));
    }

    public function test_a_recovery_code_re_enables_disabled_questions(): void
    {
        $sa = $this->account('superadmin');
        $sa->forceFill(['security_questions_disabled_at' => now()])->save();
        [$code] = RecoveryCodes::generate($sa->id);

        $this->useCode($sa->email, $code);
        $this->resetTo('Brand#New2026');

        $this->assertNull($sa->fresh()->security_questions_disabled_at);
        $this->assertSame(1, $this->auditCount('recovery.questions_reenabled', $sa->id));
    }

    public function test_wrong_answers_and_wrong_codes_share_the_lockout(): void
    {
        $sa = $this->account('superadmin');
        [$code] = RecoveryCodes::generate($sa->id);
        $this->failAnswers($sa, 3);
        $this->useCode($sa->email, 'AAAAA-AAAAA');
        $this->useCode($sa->email, 'BBBBB-BBBBB');

        $response = $this->useCode($sa->email, $code);

        $response->assertSee('Too many failed attempts.')->assertDontSee('name="new_password"', false);
        $this->assertSame(1, $this->auditCount('recovery.lockout', $sa->id));
    }

    public function test_generating_codes_needs_the_current_password_and_shows_them_once(): void
    {
        $sa = $this->account('superadmin');
        $old = RecoveryCodes::generate($sa->id);
        $this->actingAs($sa);

        $this->post(route('superadmin.settings.recovery-codes'), ['current_password' => 'wrong'])
            ->assertRedirect(route('superadmin.settings', ['tab' => 'accounts']))->assertSessionHas('message_type', 'error');
        $this->assertTrue(RecoveryCodes::consume($sa->id, $old[1]), 'A wrong password changes nothing.');

        $generated = $this->post(route('superadmin.settings.recovery-codes'), ['current_password' => 'Secret#2026']);

        $generated->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertSee('These codes are shown only now.');
        preg_match_all('/<li>([A-Z2-9]{5}-[A-Z2-9]{5})<\/li>/', $generated->getContent(), $shown);
        $this->assertCount(RecoveryCodes::COUNT, $shown[1]);
        $this->assertFalse(RecoveryCodes::consume($sa->id, $old[0]), 'A new set replaces the old codes.');
        $this->assertTrue(RecoveryCodes::consume($sa->id, $shown[1][0]));
        $this->assertSame(0, DB::table('account_recovery_codes')->where('code_hash', 'like', '%'.str_replace('-', '', $shown[1][1]).'%')->count(), 'Codes are stored hashed.');
    }

    public function test_admin_and_principal_cannot_generate_codes(): void
    {
        $this->actingAs($this->account('admin'))
            ->post(route('superadmin.settings.recovery-codes'), ['current_password' => 'Secret#2026'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, DB::table('account_recovery_codes')->count());
    }

    // ---------------------------------------------------------------- setting the questions

    public static function badQuestionSets(): array
    {
        return [
            'a repeated question' => [['childhood_best_friend', 'childhood_best_friend', 'first_concert'], ['Maria Santos', 'Rizal Street', 'Rivermaya']],
            'a question not on the list' => [['favorite_color', 'grade1_street', 'first_concert'], ['Blue sky', 'Rizal Street', 'Rivermaya']],
            'an answer too short' => [self::SECURITY_QUESTIONS, ['Ana', 'Rizal Street', 'Rivermaya']],
            'the same answer twice' => [self::SECURITY_QUESTIONS, ['Rizal Street', 'rizal street!', 'Rivermaya']],
            'their own name' => [self::SECURITY_QUESTIONS, ['Juana Bautista', 'Rizal Street', 'Rivermaya']],
            'part of their name' => [self::SECURITY_QUESTIONS, ['Bautista', 'Rizal Street', 'Rivermaya']],
            'their email' => [self::SECURITY_QUESTIONS, ['jbautista@test.local', 'Rizal Street', 'Rivermaya']],
            'only two questions' => [['childhood_best_friend', 'grade1_street'], ['Maria Santos', 'Rizal Street']],
        ];
    }

    #[DataProvider('badQuestionSets')]
    public function test_unsafe_question_sets_are_refused(array $questions, array $answers): void
    {
        $sa = $this->account('superadmin', ['full_name' => 'Juana Bautista', 'email' => 'jbautista@test.local']);

        $response = $this->actingAs($sa)->post(route('account.security-questions'), [
            'current_password' => 'Secret#2026', 'security_questions' => $questions, 'security_answers' => $answers,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(self::SECURITY_QUESTIONS, SecurityQuestions::keysFor($sa->id));
    }

    public function test_saving_questions_needs_the_current_password(): void
    {
        $sa = $this->account('superadmin');
        $new = ['first_beach', 'family_nickname', 'first_crush'];

        $this->actingAs($sa)->post(route('account.security-questions'), [
            'current_password' => 'wrong', 'security_questions' => $new, 'security_answers' => ['Boracay Beach', 'Bunso Boy', 'Maria Clara'],
        ])->assertSessionHas('error', 'Current password is incorrect.');
        $this->post(route('account.security-questions'), [
            'current_password' => 'Secret#2026', 'security_questions' => $new, 'security_answers' => ['Boracay Beach', 'Bunso Boy', 'Maria Clara'],
        ])->assertSessionHas('success', 'Security questions saved.');

        $this->assertSame($new, SecurityQuestions::keysFor($sa->id));
        $this->assertTrue(SecurityQuestions::check($sa->id, ['boracay beach', 'BUNSO-BOY', 'maria clara']));
    }

    public function test_a_super_admin_without_three_questions_can_only_use_my_account(): void
    {
        $sa = $this->account('superadmin', ['security_question' => 'Old free-text question?'], withSecurityQuestions: false);
        $this->actingAs($sa);

        $dashboard = $this->get(route('superadmin.dashboard'));
        $myAccount = $this->get(route('account'));
        $data = $this->getJson('/data/review-summary');
        $this->post(route('account.security-questions'), [
            'current_password' => 'Secret#2026', 'security_questions' => self::SECURITY_QUESTIONS, 'security_answers' => self::SECURITY_ANSWERS,
        ]);
        $dashboardAfter = $this->get(route('superadmin.dashboard'));

        $dashboard->assertRedirect(route('account'));
        $myAccount->assertSee('Set your 3 security questions to continue using the system.');
        $data->assertForbidden()->assertJson(['success' => false]);
        $dashboardAfter->assertOk();
    }

    // ---------------------------------------------------------------- after-login warning

    public function test_the_next_login_warns_about_failed_recovery_tries_since_the_last_login(): void
    {
        $sa = $this->account('superadmin');
        $sa->forceFill(['last_login_at' => now()->subDay()])->save();
        DB::table('audit_logs')->insert(['actor' => $sa->email, 'action' => 'recovery.failed', 'subject_type' => 'account', 'subject_id' => $sa->id, 'created_at' => now()->subDays(2)]);
        $this->failAnswers($sa, 2);
        $latest = DB::table('audit_logs')->where('action', 'recovery.failed')->max('created_at');

        $firstLogin = $this->followingRedirects()->post(route('login'), ['email' => $sa->email, 'password' => 'Secret#2026']);
        $this->post(route('logout'));
        $secondLogin = $this->followingRedirects()->post(route('login'), ['email' => $sa->email, 'password' => 'Secret#2026']);

        $firstLogin->assertSee('2 failed password-recovery attempts since your last login')
            ->assertSee('latest: '.date('M j, Y g:i A', strtotime($latest)));
        $secondLogin->assertDontSee('failed password-recovery');
    }

    // ---------------------------------------------------------------- server reset command

    public function test_the_reset_command_sets_a_typed_password_and_requires_new_questions(): void
    {
        $sa = $this->account('superadmin');
        $sa->forceFill(['security_questions_disabled_at' => now()])->save();
        RecoveryCodes::generate($sa->id);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $sa->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('account:reset-superadmin', ['email' => $sa->email])
            ->expectsConfirmation("Reset the password of {$sa->full_name} ({$sa->email})?", 'yes')
            ->expectsQuestion('New password (at least 8 characters)', 'Server#Reset1')
            ->expectsQuestion('Confirm new password', 'Server#Reset1')
            ->expectsOutputToContain('Recovery codes were kept')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('Server#Reset1', $sa->fresh()->password));
        $this->assertFalse(SecurityQuestions::hasAll($sa->id));
        $this->assertNull($sa->fresh()->security_questions_disabled_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $sa->id)->count());
        $this->assertSame(RecoveryCodes::COUNT, RecoveryCodes::remaining($sa->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.password_reset_console', 'subject_id' => $sa->id, 'actor' => 'console']);
    }

    public function test_the_reset_command_can_generate_a_password_and_revoke_codes(): void
    {
        $sa = $this->account('superadmin');
        RecoveryCodes::generate($sa->id);

        $this->artisan('account:reset-superadmin', ['email' => $sa->email, '--generate' => true, '--revoke-codes' => true])
            ->expectsConfirmation("Reset the password of {$sa->full_name} ({$sa->email})?", 'yes')
            ->expectsOutputToContain('Temporary password (shown once):')
            ->expectsOutputToContain('10 recovery code(s) deleted.')
            ->assertSuccessful();

        $this->assertFalse(Hash::check('Secret#2026', $sa->fresh()->password));
        $this->assertSame(0, RecoveryCodes::remaining($sa->id));
    }

    public function test_the_reset_command_changes_nothing_without_confirmation_or_for_other_accounts(): void
    {
        $sa = $this->account('superadmin');
        $admin = $this->account('admin');

        $this->artisan('account:reset-superadmin', ['email' => $sa->email])
            ->expectsConfirmation("Reset the password of {$sa->full_name} ({$sa->email})?", 'no')
            ->assertFailed();
        $this->artisan('account:reset-superadmin', ['email' => $admin->email])
            ->expectsOutput('No active Super Admin has that email.')
            ->assertFailed();

        $this->assertTrue(Hash::check('Secret#2026', $sa->fresh()->password));
        $this->assertTrue(SecurityQuestions::hasAll($sa->id));
    }
}
