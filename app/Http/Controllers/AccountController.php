<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * My Account: change password (every role) and the security question used
 * by Forgot Password (Super Admin only). Ported from my-account.php. Only
 * ever touches the signed-in account's own row.
 */
class AccountController extends Controller
{
    public function show()
    {
        return view('account.show', ['account' => Auth::user()]);
    }

    public function updatePassword(Request $request)
    {
        $account = $request->user();

        $current = (string) $request->input('current_password');
        $new = (string) $request->input('new_password');
        $confirm = (string) $request->input('confirm_password');

        $currentValid = Hash::check($current, $account->password);

        $error = match (true) {
            ! $currentValid => 'Current password is incorrect.',
            Hash::check($new, $account->password) => 'New password must be different from your current password.',
            strlen($new) < 8 => 'New password must be at least 8 characters.',
            $new !== $confirm => 'New password and confirmation do not match.',
            default => null,
        };

        if ($error) {
            if (! $currentValid) {
                Audit::log('password.change_failed', 'account', $account->id);
            }

            return back()->with('error', $error);
        }

        $account->forceFill(['password' => $new])->save();

        // Sign out this account's other sessions (other browsers / PCs).
        Auth::logoutOtherDevices($new);

        Audit::log('password.changed', 'account', $account->id);

        return back()->with('success', 'Password changed successfully.');
    }

    public function updateSecurityQuestion(Request $request)
    {
        $account = $request->user();

        abort_unless($account->role === 'superadmin', 403);

        $question = trim((string) $request->input('security_question'));
        $answer = trim((string) $request->input('security_answer'));

        if ($question === '' || $answer === '') {
            return back()->with('error', 'Please provide both a security question and an answer.');
        }

        if (mb_strlen($answer) < 4) {
            return back()->with('error', 'The answer must be at least 4 characters. Choose one that others cannot guess.');
        }

        $account->forceFill([
            'security_question' => mb_substr($question, 0, 255),
            // Normalized the same way Forgot Password verifies it.
            'security_answer_hash' => Hash::make(strtolower($answer)),
        ])->save();

        Audit::log('security_question.changed', 'account', $account->id);

        return back()->with('success', 'Security question saved.');
    }
}
