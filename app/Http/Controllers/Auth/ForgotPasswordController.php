<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\AccountRecovery;
use App\Support\Audit;
use App\Support\RecoveryCodes;
use App\Support\SecurityQuestions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Password recovery, ported from forgot-password.php.
 *
 * Super Admin: answers all 3 security questions on one page, or uses a
 * one-time recovery code, then sets a new password. Answers and codes share
 * the limits in App\Support\AccountRecovery, and a failure never says which
 * answer was wrong.
 *
 * Admin / Principal: files a request the Super Admin resolves in Settings;
 * the reply is the same whether or not the email exists.
 */
class ForgotPasswordController extends Controller
{
    private const ANSWERS_DONT_MATCH = "Your answers don't match.";

    private const CODE_INVALID = 'That recovery code is not valid for this email.';

    private const QUESTIONS_DISABLED = 'Security questions are turned off for this account after repeated failed attempts. Use a recovery code, or have the password reset on the server PC.';

    private const QUESTIONS_NOT_SET = 'Security questions are not set up for this account. Use a recovery code, or have the password reset on the server PC.';

    public function show(Request $request): View
    {
        // Fresh GET — always start over.
        $request->session()->forget(['fp_email', 'fp_verified', 'fp_method']);

        return $this->page($request->query('use') === 'code' ? 'code' : 'email');
    }

    public function submit(Request $request): View
    {
        return match ($request->input('step')) {
            'questions' => $this->checkAnswers($request),
            'code' => $this->checkCode($request),
            'reset' => $this->reset($request),
            default => $this->checkEmail($request),
        };
    }

    private function checkEmail(Request $request): View
    {
        $email = trim((string) $request->input('email'));

        $ipKey = 'forgot-email:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return $this->page('email', 'Too many requests. Please wait a minute and try again.');
        }
        RateLimiter::hit($ipKey, 60);

        $account = Account::query()->active()->where('email', $email)->first();

        if ($account && $account->role === 'superadmin') {
            $request->session()->put('fp_email', $email);
            $request->session()->forget(['fp_verified', 'fp_method']);

            return $this->questionsPage($account, AccountRecovery::blockedMessage($email, $request->ip()) ?? '');
        }

        if ($account) {
            $alreadyPending = DB::table('password_reset_requests')
                ->where('account_id', $account->id)->where('status', 'pending')->exists();

            if (! $alreadyPending) {
                DB::table('password_reset_requests')->insert(['account_id' => $account->id, 'status' => 'pending']);
                Audit::log('password_reset.requested', 'account', $account->id, actor: $email);
            }
        }

        // Same reply whether or not an Admin / Principal account exists.
        return $this->page('request_sent',
            'Password Reset Request Sent. If this email belongs to an Admin or Principal account, your request has been sent to the Super Admin. Please wait for the Super Admin to reset your password.',
            'success');
    }

    private function checkAnswers(Request $request): View
    {
        $email = $request->session()->get('fp_email', '');
        $account = $this->superAdmin($email);

        if (! $account) {
            return $this->page('email', 'Your session expired. Please start again.');
        }

        if ($blocked = AccountRecovery::blockedMessage($email, $request->ip())) {
            return $this->questionsPage($account, $blocked);
        }

        if (AccountRecovery::questionsDisabled($account) || ! SecurityQuestions::hasAll($account->id)) {
            return $this->questionsPage($account);
        }

        if (SecurityQuestions::check($account->id, (array) $request->input('answers', []))) {
            AccountRecovery::recordSuccess($email);
            $request->session()->put(['fp_verified' => true, 'fp_method' => 'questions']);

            return $this->page('reset');
        }

        AccountRecovery::recordFailure($account, $email, 'questions', $request->ip());

        return $this->questionsPage($account->refresh(),
            AccountRecovery::blockedMessage($email, $request->ip()) ?? self::ANSWERS_DONT_MATCH);
    }

    private function checkCode(Request $request): View
    {
        $email = trim((string) $request->input('email'));

        if ($blocked = AccountRecovery::blockedMessage($email, $request->ip())) {
            return $this->page('code', $blocked, email: $email);
        }

        $account = $this->superAdmin($email);

        if ($account && RecoveryCodes::consume($account->id, (string) $request->input('recovery_code'))) {
            AccountRecovery::recordSuccess($email);
            Audit::log('recovery_code.used', 'account', $account->id,
                ['codes_left' => RecoveryCodes::remaining($account->id)], actor: $email);

            $request->session()->put(['fp_email' => $email, 'fp_verified' => true, 'fp_method' => 'recovery_code']);

            return $this->page('reset');
        }

        AccountRecovery::recordFailure($account, $email, 'recovery_code', $request->ip());

        return $this->page('code', AccountRecovery::blockedMessage($email, $request->ip()) ?? self::CODE_INVALID, email: $email);
    }

    private function reset(Request $request): View
    {
        $email = $request->session()->get('fp_email', '');
        $method = $request->session()->get('fp_method', 'questions');

        if ($email === '' || ! $request->session()->get('fp_verified')) {
            return $this->page('email', 'Your session expired. Please start again.');
        }

        $new = (string) $request->input('new_password');
        $confirm = (string) $request->input('confirm_password');

        $account = $this->superAdmin($email);

        if (strlen($new) < 8) {
            return $this->page('reset', 'Password must be at least 8 characters.');
        }

        if ($new !== $confirm) {
            return $this->page('reset', 'Password and Confirm Password do not match.');
        }

        if ($account && Hash::check($new, $account->password)) {
            return $this->page('reset', 'New password must be different from your current password.');
        }

        $request->session()->forget(['fp_email', 'fp_verified', 'fp_method']);

        if (! $account) {
            return $this->page('email', 'Unable to reset the password. Please start again.');
        }

        $account->forceFill(['password' => $new, 'remember_token' => null])->save();

        // A recovery code means the answers were forgotten: new questions
        // are required at next login, and disabled questions come back.
        if ($method === 'recovery_code') {
            AccountRecovery::afterReset($account, $email);
        }

        Audit::log('password_reset.completed', 'account', $account->id, ['method' => $method], actor: $email);

        return $this->page('done', $method === 'recovery_code'
            ? 'Password reset successfully. Log in with your new password; you will be asked to set 3 new security questions.'
            : 'Password reset successfully. You can now log in with your new password.', 'success');
    }

    private function superAdmin(string $email): ?Account
    {
        return $email === '' ? null
            : Account::query()->active()->where('role', 'superadmin')->where('email', $email)->first();
    }

    /** The 3 questions, or the recovery code form when they cannot be used. */
    private function questionsPage(Account $account, string $message = ''): View
    {
        if (AccountRecovery::questionsDisabled($account)) {
            return $this->page('code', self::QUESTIONS_DISABLED, email: $account->email);
        }

        if (! SecurityQuestions::hasAll($account->id)) {
            return $this->page('code', self::QUESTIONS_NOT_SET, email: $account->email);
        }

        return $this->page('questions', $message, questions: SecurityQuestions::questionsFor($account->id));
    }

    /**
     * @param  list<string>  $questions
     */
    private function page(string $step, string $message = '', string $type = 'error', array $questions = [], string $email = ''): View
    {
        return view('auth.forgot-password', [
            'step' => $step,
            'message' => $message,
            'messageType' => $type,
            'questions' => $questions,
            'email' => $email,
        ]);
    }
}
