<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\KioskDevice;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Settings page and its Account Management actions (was superadmin/settings.php).
 * The other cards (recording, breaks, positions, recycle bin, backups,
 * audit log) use the /data endpoints.
 */
class SettingsController extends Controller
{
    public function show(Request $request)
    {
        $accounts = Account::query()->active()
            ->whereIn('role', ['admin', 'principal'])
            ->orderByDesc('created_at')
            ->get(['id', 'full_name', 'email', 'role', 'created_at', 'last_login_at']);

        $currentKiosk = KioskDevice::findByToken($request->cookie(KioskDevice::COOKIE));

        $kiosks = KioskDevice::query()->orderByDesc('created_at')->get();

        return view('superadmin.settings', [
            'accounts' => $accounts,
            'kiosks' => $kiosks,
            'currentKioskId' => $currentKiosk?->id,
        ]);
    }

    public function createAccount(Request $request)
    {
        $fullName = preg_replace('/\s+/', ' ', trim((string) $request->input('full_name')));
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');
        $confirm = (string) $request->input('confirm_password');
        $role = (string) $request->input('role');

        $errors = [];

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        } elseif (Account::query()->where('email', $email)->exists()) {
            $errors[] = 'That email is already in use.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Password and Confirm Password do not match.';
        }

        // Only Admin / Principal can be created here, whatever the request says.
        if (! in_array($role, ['admin', 'principal'], true)) {
            $errors[] = 'Invalid role selected.';
        }

        if ($errors) {
            return $this->back(implode(' ', $errors), 'error');
        }

        $account = Account::create([
            'full_name' => mb_substr($fullName, 0, 150),
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ]);

        Audit::log('account.created', 'account', $account->id, ['email' => $email, 'role' => $role]);

        return $this->back('Account created successfully.');
    }

    public function archiveAccount(Request $request)
    {
        $id = (int) $request->input('account_id');

        $changed = DB::table('accounts')
            ->where('id', $id)
            ->whereIn('role', ['admin', 'principal'])
            ->where('is_deleted', 0)
            ->update(['is_deleted' => 1, 'deleted_at' => now(), 'deleted_by' => $request->user()->email]);

        if (! $changed) {
            return $this->back('Unable to archive account.', 'error');
        }

        Audit::log('account.archived', 'account', $id);

        return $this->back('Account moved to Recycle Bin.');
    }

    public function resetPassword(Request $request)
    {
        $id = (int) $request->input('account_id');
        $new = (string) $request->input('new_password');
        $confirm = (string) $request->input('confirm_password');

        // Never a Super Admin, never an archived account.
        $account = Account::query()->active()->whereIn('role', ['admin', 'principal'])->find($id);

        $error = match (true) {
            ! $account => 'Invalid account.',
            strlen($new) < 8 => 'New password must be at least 8 characters.',
            $new !== $confirm => 'New password and confirmation do not match.',
            Hash::check($new, $account->password) => 'New password must be different from the current password.',
            default => null,
        };

        if ($error) {
            return $this->back($error, 'error');
        }

        $account->forceFill(['password' => $new, 'remember_token' => null])->save();

        // The request is handled: clear it from the dashboard.
        DB::table('password_reset_requests')
            ->where('account_id', $account->id)
            ->where('status', 'pending')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        // Sign the account out everywhere: its sessions were opened with the old password.
        DB::table('sessions')->where('user_id', $account->id)->delete();

        Audit::log('account.password_reset', 'account', $account->id);

        return $this->back('Password reset successfully.');
    }

    private function back(string $message, string $type = 'success')
    {
        return redirect()->route('superadmin.settings')
            ->with('message', $message)
            ->with('message_type', $type);
    }
}
