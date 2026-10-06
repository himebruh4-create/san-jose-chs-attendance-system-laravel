<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /** Failed attempts allowed per email + IP before a lockout. */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()->withInput($request->only('email'))
                ->with('error', "Too many failed attempts. Try again in {$minutes} minute(s).");
        }

        $ok = Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_deleted' => false,
        ]);

        if (! $ok) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            Audit::log('login.failed', details: ['email' => $credentials['email']], actor: $credentials['email']);

            return back()->withInput($request->only('email'))->with('error', 'Invalid email or password!');
        }

        RateLimiter::clear($key);

        // New session ID on login: a session fixed before login is useless.
        $request->session()->regenerate();

        $account = $request->user();
        $account->forceFill(['last_login_at' => now()])->save();
        Audit::log('login', 'account', $account->id);

        return redirect()->intended(route($account->homeRoute()));
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            Audit::log('logout', 'account', $request->user()->id);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
