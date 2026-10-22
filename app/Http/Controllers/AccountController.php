<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\SecurityQuestions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * My Account: change password (every role) and the 3 security questions
 * used by Forgot Password (Super Admin only). Ported from my-account.php.
 * Only ever touches the signed-in account's own row.
 */
class AccountController extends Controller
{
    public function show()
    {
        $account = Auth::user();

        return view('account.show', [
            'account' => $account,
            'securityQuestionKeys' => $account->role === 'superadmin' ? SecurityQuestions::keysFor($account->id) : [],
        ]);
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

    /** The Super Admin's 3 security questions (all replaced together). */
    public function updateSecurityQuestions(Request $request)
    {
        $account = $request->user();

        abort_unless($account->role === 'superadmin', 403);

        if (! Hash::check((string) $request->input('current_password'), $account->password)) {
            Audit::log('security_questions.change_failed', 'account', $account->id);

            return back()->with('error', 'Current password is incorrect.');
        }

        $questions = (array) $request->input('security_questions', []);
        $answers = (array) $request->input('security_answers', []);

        if ($problems = SecurityQuestions::problems($questions, $answers, $account->full_name, $account->email)) {
            return back()->with('error', implode(' ', $problems));
        }

        SecurityQuestions::save($account->id, array_values($questions), array_values($answers));
        Audit::log('security_questions.changed', 'account', $account->id);

        return back()->with('success', 'Security questions saved.');
    }
}
