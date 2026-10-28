<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * One device per account (Super Admin, Admin and Principal alike; two
 * different accounts never affect each other). The kiosk has no login and
 * is never checked.
 *
 * - A login becomes the account's active session (account_sessions). The
 *   device it replaced may answer "Stay logged in" once, within
 *   RESPONSE_SECONDS of seeing the prompt; the device that loses to "Stay
 *   logged in" may not, so two devices cannot keep signing each other out.
 * - Every signed-in request is checked (EnsureSingleActiveSession), and
 *   every open page asks GET /session/status every 10 seconds
 *   (public/js/session-guard.js).
 * - A session with no row of its own (signed in before this existed) becomes
 *   the active one only while no other device is: a "remember me" cookie can
 *   never bring back a replaced session.
 * - The 10-second check would keep an idle page signed in forever, so a
 *   session idle (no request other than the check) for the session lifetime
 *   is ended here instead.
 */
class ActiveSessions
{
    public const RESPONSE_SECONDS = 30;

    /** Allowance for the answer to reach the server after the countdown. */
    private const RESPONSE_GRACE_SECONDS = 5;

    /** last_seen_at is written at most this often. */
    private const SEEN_EVERY_SECONDS = 60;

    public const ACTIVE = 'active';

    /** Replaced by another device and may still answer the prompt. */
    public const REPLACED = 'replaced';

    /** Signed out: replaced and may not answer (or no row while another device is active). */
    public const OTHER_DEVICE = 'other_device';

    /** Signed out: the countdown ended without an answer. */
    public const NO_RESPONSE = 'no_response';

    /** Signed out: idle for the session lifetime, or already ended. */
    public const EXPIRED = 'expired';

    /** Session data key holding this browser's account_sessions id. */
    private const SESSION_KEY = 'account_session_id';

    public const OTHER_DEVICE_NOTICE = 'You were logged out because this account is being used on another device.';

    /** Right after a login: this session becomes the account's active one. */
    public static function start(Account $account, Request $request): void
    {
        [$session, $replacedAny] = DB::transaction(function () use ($account, $request) {
            // One login at a time per account.
            Account::whereKey($account->id)->lockForUpdate()->first();

            // Only the device replaced by THIS login may still answer the prompt.
            AccountSession::where('account_id', $account->id)
                ->whereNotNull('replaced_at')->whereNull('ended_at')
                ->update(['can_reclaim' => false]);

            $previous = AccountSession::active()->where('account_id', $account->id)->get();
            $session = self::open($account, $request);
            $replacedAny = false;

            foreach ($previous as $old) {
                if (self::isIdle($old)) {
                    self::end($old, self::EXPIRED);

                    continue;
                }

                $old->update(['replaced_at' => now(), 'replaced_by_id' => $session->id, 'can_reclaim' => true, 'notified_at' => null]);
                $replacedAny = true;
            }

            return [$session, $replacedAny];
        });

        if ($replacedAny) {
            Audit::log('session.signed_in_elsewhere', 'account', $account->id, $session->device());
        }
    }

    /**
     * This request's session and what may happen to it (one of the status
     * constants). $isInteraction is false for the 10-second check, which
     * must not count as activity.
     *
     * @return array{status: string, session: ?AccountSession}
     */
    public static function check(Account $account, Request $request, bool $isInteraction): array
    {
        $session = self::current($account, $request);

        if (! $session) {
            foreach (AccountSession::active()->where('account_id', $account->id)->get() as $row) {
                if (! self::isIdle($row)) {
                    return ['status' => self::OTHER_DEVICE, 'session' => null];
                }

                self::end($row, self::EXPIRED);
            }

            $session = self::open($account, $request);
        }

        if ($session->ended_at) {
            return ['status' => self::EXPIRED, 'session' => $session];
        }

        if (self::isIdle($session)) {
            self::end($session, self::EXPIRED);

            return ['status' => self::EXPIRED, 'session' => $session];
        }

        if ($session->replaced_at === null) {
            if ($isInteraction && $session->last_seen_at->lt(now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
                $session->update(['last_seen_at' => now()]);
            }

            return ['status' => self::ACTIVE, 'session' => $session];
        }

        if (self::mayAnswer($session)) {
            return ['status' => self::REPLACED, 'session' => $session];
        }

        $status = $session->can_reclaim ? self::NO_RESPONSE : self::OTHER_DEVICE;
        self::end($session, $status);

        return ['status' => $status, 'session' => $session];
    }

    /** The prompt is on screen: its countdown starts now (once). */
    public static function notify(AccountSession $session): void
    {
        if ($session->notified_at === null) {
            $session->update(['notified_at' => now()]);
        }
    }

    public static function secondsLeft(AccountSession $session): int
    {
        if ($session->notified_at === null) {
            return self::RESPONSE_SECONDS;
        }

        return max(0, $session->notified_at->timestamp + self::RESPONSE_SECONDS - now()->timestamp);
    }

    /**
     * "Stay logged in": this session is the active one again and the device
     * that replaced it is signed out without a prompt of its own.
     */
    public static function reclaim(AccountSession $session): bool
    {
        return DB::transaction(function () use ($session) {
            Account::whereKey($session->account_id)->lockForUpdate()->first();
            $session->refresh();

            if (! self::mayAnswer($session)) {
                return false;
            }

            AccountSession::active()->where('account_id', $session->account_id)->update([
                'replaced_at' => now(), 'replaced_by_id' => $session->id, 'can_reclaim' => false, 'notified_at' => null,
            ]);

            $session->update([
                'replaced_at' => null, 'replaced_by_id' => null, 'can_reclaim' => false,
                'notified_at' => null, 'last_seen_at' => now(),
            ]);

            return true;
        });
    }

    /** "Signed in on Chrome on Windows (192.168.1.20) at 9:15 AM", for the prompt. */
    public static function promptMessage(?AccountSession $other): string
    {
        $where = $other
            ? "{$other->browser()} on {$other->operatingSystem()} ({$other->ip_address})"
            : 'another device';
        $at = $other
            ? ' at '.$other->logged_in_at->format($other->logged_in_at->isToday() ? 'g:i A' : 'M j, Y g:i A')
            : '';

        return "Someone signed in to this account on {$where}{$at}. For security, only one device can use this account at a time.";
    }

    /** A normal logout: the next login on any device starts without a prompt. */
    public static function endCurrent(Request $request): void
    {
        $session = $request->user() ? self::current($request->user(), $request) : null;

        if ($session && $session->ended_at === null) {
            self::end($session, 'logout');
        }
    }

    /**
     * Signs this session out (audit first, while the account is still known)
     * and sends the browser to the login page: a redirect for a page, a 401
     * the pages' fetch wrapper follows for a data request.
     */
    public static function signOut(Request $request, string $status, ?AccountSession $session): Response
    {
        $audit = [
            self::OTHER_DEVICE => 'session.logged_out_other_device',
            self::NO_RESPONSE => 'session.logged_out_no_response',
        ][$status] ?? null;

        if ($session && $session->ended_at === null) {
            self::end($session, $status);
        }

        if ($audit) {
            Audit::log($audit, 'account', $request->user()->id, $session ? $session->device() : [
                'ip' => $request->ip(),
                'browser' => UserAgent::browser((string) $request->userAgent()),
                'os' => UserAgent::operatingSystem((string) $request->userAgent()),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $notice = $status === self::OTHER_DEVICE ? self::OTHER_DEVICE_NOTICE : null;

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $notice ?? 'You have been logged out.',
                'session_ended' => true,
                'redirect' => route('login'),
            ], 401);
        }

        $redirect = redirect()->route('login');

        return $notice ? $redirect->with('error', $notice) : $redirect;
    }

    /** This browser's row, by the id kept in its session data. */
    private static function current(Account $account, Request $request): ?AccountSession
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id ? AccountSession::where('account_id', $account->id)->find($id) : null;
    }

    private static function open(Account $account, Request $request): AccountSession
    {
        $session = AccountSession::create([
            'account_id' => $account->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'logged_in_at' => now(),
            'last_seen_at' => now(),
        ]);

        $request->session()->put(self::SESSION_KEY, $session->id);

        return $session;
    }

    private static function mayAnswer(AccountSession $session): bool
    {
        return $session->ended_at === null
            && $session->replaced_at !== null
            && $session->can_reclaim
            && ($session->notified_at === null
                || now()->lte($session->notified_at->copy()->addSeconds(self::RESPONSE_SECONDS + self::RESPONSE_GRACE_SECONDS)));
    }

    private static function isIdle(AccountSession $session): bool
    {
        return $session->last_seen_at->lt(now()->subMinutes((int) config('session.lifetime')));
    }

    private static function end(AccountSession $session, string $reason): void
    {
        $session->update(['ended_at' => now(), 'ended_reason' => $reason]);
    }
}
