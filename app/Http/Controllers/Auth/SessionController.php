<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountSession;
use App\Support\ActiveSessions;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The open page's side of "one device per account" (public/js/session-guard.js).
 * EnsureSingleActiveSession has already signed out any session that may no
 * longer be used, so each action here sees an active session or one that
 * was replaced and may still answer.
 */
class SessionController extends Controller
{
    /** The 10-second check. A replaced session gets the prompt's text and countdown. */
    public function status(Request $request): JsonResponse
    {
        $session = $this->session($request);

        if ($session->replaced_at === null) {
            return response()->json(['success' => true, 'status' => ActiveSessions::ACTIVE]);
        }

        ActiveSessions::notify($session);

        return response()->json([
            'success' => true,
            'status' => ActiveSessions::REPLACED,
            'message' => ActiveSessions::promptMessage($session->replacedBy),
            'seconds_left' => ActiveSessions::secondsLeft($session),
        ]);
    }

    /** "Stay logged in": this device keeps the account; the other one is signed out. */
    public function stay(Request $request): Response
    {
        $session = $this->session($request);

        if ($session->replaced_at === null) {
            return response()->json(['success' => true, 'status' => ActiveSessions::ACTIVE]);
        }

        if (! ActiveSessions::reclaim($session)) {
            return ActiveSessions::signOut($request, ActiveSessions::NO_RESPONSE, $session);
        }

        Audit::log('session.stayed', 'account', $session->account_id, $session->device());

        return response()->json(['success' => true, 'status' => ActiveSessions::ACTIVE]);
    }

    /** The countdown ended without an answer. */
    public function timeout(Request $request): Response
    {
        $session = $this->session($request);

        if ($session->replaced_at === null) {
            return response()->json(['success' => true, 'status' => ActiveSessions::ACTIVE]);
        }

        return ActiveSessions::signOut($request, ActiveSessions::NO_RESPONSE, $session);
    }

    private function session(Request $request): AccountSession
    {
        return $request->attributes->get('account_session');
    }
}
