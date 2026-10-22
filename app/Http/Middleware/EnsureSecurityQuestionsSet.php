<?php

namespace App\Http\Middleware;

use App\Support\SecurityQuestions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Super Admin without all 3 security questions (an account from before
 * they existed, one made with account:create-superadmin, or after a reset
 * by recovery code or on the server) can only use My Account until the
 * questions are set. Logout is outside the signed-in routes, so it works.
 */
class EnsureSecurityQuestionsSet
{
    /** What stays open while the questions are missing. */
    private const ALLOWED_ROUTES = ['account', 'account.password', 'account.security-questions'];

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account || $account->role !== 'superadmin' || $request->routeIs(...self::ALLOWED_ROUTES)
            || SecurityQuestions::hasAll($account->id)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => 'Set your 3 security questions in My Account first.'], 403);
        }

        return redirect()->route('account');
    }
}
