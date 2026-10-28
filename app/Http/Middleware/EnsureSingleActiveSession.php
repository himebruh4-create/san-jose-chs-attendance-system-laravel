<?php

namespace App\Http\Middleware;

use App\Support\ActiveSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One device per account (App\Support\ActiveSessions), checked on every
 * signed-in request. A session replaced by another device reaches only the
 * /session endpoints until it answers the prompt: pages show the prompt
 * alone and data requests get a 409. A session that may not answer is
 * signed out.
 */
class EnsureSingleActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account) {
            return $next($request);
        }

        ['status' => $status, 'session' => $session] = ActiveSessions::check($account, $request, ! $request->routeIs('session.status'));

        $request->attributes->set('account_session', $session);

        if ($status === ActiveSessions::ACTIVE || ($status === ActiveSessions::REPLACED && $request->routeIs('session.*'))) {
            return $next($request);
        }

        if ($status === ActiveSessions::REPLACED) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This account was signed in on another device.',
                    'session_replaced' => true,
                ], 409);
            }

            return response()->view('auth.session-replaced', [], 409);
        }

        return ActiveSessions::signOut($request, $status, $session);
    }
}
