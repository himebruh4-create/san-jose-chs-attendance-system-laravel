<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an account the moment it is moved to the recycle bin, instead
 * of letting its open session keep working.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if ($account && $account->is_deleted) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Your account is no longer active.'], 401);
            }

            return redirect()->route('login')->with('error', 'Your account is no longer active.');
        }

        return $next($request);
    }
}
