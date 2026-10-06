<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RedirectToSetupWhenNoSuperAdmin;
use App\Models\Account;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * First-run setup: creates the first Super Admin on a fresh install.
 *
 * Only available while no Super Admin exists, and only from the server PC
 * itself (localhost) unless SETUP_ALLOW_REMOTE=true, so nobody else on the
 * network can claim a fresh install first. Replaces the native-PHP
 * SUPERADMIN_BOOTSTRAP_PASSWORD environment variable.
 */
class SetupController extends Controller
{
    public function show(Request $request)
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        return view('auth.setup');
    }

    public function store(Request $request)
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:accounts,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'security_question' => ['required', 'string', 'max:255'],
            'security_answer' => ['required', 'string', 'min:4', 'max:255'],
        ]);

        $account = DB::transaction(function () use ($data) {
            // Re-check inside the transaction: two browsers finishing setup
            // at once must not both become Super Admin.
            abort_if(Account::query()->where('role', 'superadmin')->lockForUpdate()->exists(), 409, 'Setup is already complete.');

            return Account::create([
                'full_name' => trim($data['full_name']),
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
                'role' => 'superadmin',
                'security_question' => trim($data['security_question']),
                'security_answer_hash' => bcrypt(strtolower(trim($data['security_answer']))),
            ]);
        });

        Audit::log('setup.superadmin_created', 'account', $account->id, actor: $account->email);

        Auth::login($account);
        $request->session()->regenerate();

        return redirect()->route('superadmin.dashboard');
    }

    private function guard(Request $request)
    {
        if (RedirectToSetupWhenNoSuperAdmin::superAdminExists()) {
            return redirect()->route('login');
        }

        $local = in_array($request->ip(), ['127.0.0.1', '::1'], true);

        if (! $local && ! config('app.setup_allow_remote')) {
            abort(403, 'First-run setup can only be completed on the server PC itself (open http://localhost).');
        }

        return null;
    }
}
