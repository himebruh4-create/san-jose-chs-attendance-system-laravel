<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:superadmin,admin` — the signed-in account must have one of the
 * listed roles. Data requests get the JSON shape the pages already handle.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $account = $request->user();

        if ($account && $account->hasRole(...$roles)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access.'], 403);
        }

        return $account
            ? redirect()->route($account->homeRoute())
            : redirect()->route('login');
    }
}
