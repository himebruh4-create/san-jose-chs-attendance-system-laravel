<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Password recovery, ported from forgot-password.php.
 *
 * Super Admin: answers their security question, then sets a new password.
 * Admin / Principal: files a request the Super Admin resolves in Settings.
 *
 * New: the security answer allows 5 tries per 15 minutes, and the Admin /
 * Principal branch gives the same reply whether or not the email exists.
 */
class ForgotPasswordController extends Controller
{
    private const ANSWER_ATTEMPTS = 5;

    private const ANSWER_DECAY = 900;

    private const GENERIC = 'Unable to proceed with a password reset for that email.';

    public function show(Request $request)
    {
        // Fresh GET — always start over.
        $request->session()->forget(['fp_email', 'fp_verified']);

        return view('auth.forgot-password', ['step' => 'email']);
    }

    public function submit(Request $request)
    {
        return match ($request->input('step')) {
            'question' => $this->checkAnswer($request),
            'reset' => $this->reset($request),
            default => $this->checkEmail($request),
        };
    }

    private function checkEmail(Request $request)
    {
        $email = trim((string) $request->input('email'));

        $ipKey = 'forgot-email:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return $this->page('email', 'Too many requests. Please wait a minute and try again.');
        }
        RateLimiter::hit($ipKey, 60);

        $account = Account::query()->active()->where('email', $email)->first();

        if ($account && $account->role === 'superadmin') {
            if (empty($account->security_question)) {
                return $this->page('email', self::GENERIC);
            }

            $request->session()->put('fp_email', $email);
            $request->session()->forget('fp_verified');

            return $this->page('question', question: $account->security_question);
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

    private function checkAnswer(Request $request)
    {
        $email = $request->session()->get('fp_email', '');

        if ($email === '') {
            return $this->page('email', 'Your session expired. Please start again.');
        }

        $account = Account::query()->active()->where('role', 'superadmin')->where('email', $email)->first();
        $key = 'forgot-answer:'.Str::lower($email);

        if (RateLimiter::tooManyAttempts($key, self::ANSWER_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return $this->page('question', "Too many incorrect answers. Try again in {$minutes} minute(s).", question: $account?->security_question ?? '');
        }

        $answer = strtolower(trim((string) $request->input('security_answer')));

        if ($account && $account->security_answer_hash && Hash::check($answer, $account->security_answer_hash)) {
            RateLimiter::clear($key);
            $request->session()->put('fp_verified', true);

            return $this->page('reset');
        }

        RateLimiter::hit($key, self::ANSWER_DECAY);
        Audit::log('password_reset.wrong_answer', 'account', $account?->id, actor: $email);

        return $this->page('question', 'Incorrect answer. Please try again.', question: $account?->security_question ?? '');
    }

    private function reset(Request $request)
    {
        $email = $request->session()->get('fp_email', '');

        if ($email === '' || ! $request->session()->get('fp_verified')) {
            return $this->page('email', 'Your session expired. Please start again.');
        }

        $new = (string) $request->input('new_password');
        $confirm = (string) $request->input('confirm_password');

        $account = Account::query()->active()->where('role', 'superadmin')->where('email', $email)->first();

        if (strlen($new) < 8) {
            return $this->page('reset', 'Password must be at least 8 characters.');
        }

        if ($new !== $confirm) {
            return $this->page('reset', 'Password and Confirm Password do not match.');
        }

        if ($account && Hash::check($new, $account->password)) {
            return $this->page('reset', 'New password must be different from your current password.');
        }

        $request->session()->forget(['fp_email', 'fp_verified']);

        if (! $account) {
            return $this->page('email', 'Unable to reset the password. Please start again.');
        }

        $account->forceFill(['password' => $new, 'remember_token' => null])->save();
        Audit::log('password_reset.completed', 'account', $account->id, actor: $email);

        return $this->page('done', 'Password reset successfully. You can now log in with your new password.', 'success');
    }

    private function page(string $step, string $message = '', string $type = 'error', string $question = '')
    {
        return view('auth.forgot-password', [
            'step' => $step,
            'message' => $message,
            'messageType' => $type,
            'securityQuestion' => $question,
        ]);
    }
}
