<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Limits on the Super Admin's Forgot Password (security answers and
 * recovery codes share them), and its audit trail:
 *
 * - 5 failed tries for an email: locked for 15 minutes;
 * - 3 of those lockouts within 24 hours: the account's security questions
 *   are disabled until a recovery code or the server reset command is used;
 * - 20 failed tries from one IP within an hour, across all accounts.
 *
 * Every failed try and every lockout is written to the Audit Log, which is
 * also where the 24-hour lockout count and the after-login warning come
 * from.
 */
class AccountRecovery
{
    public const MAX_FAILURES = 5;

    public const LOCKOUT_SECONDS = 900;

    public const LOCKOUTS_TO_DISABLE = 3;

    public const LOCKOUT_COUNT_HOURS = 24;

    public const IP_MAX_FAILURES = 20;

    public const IP_WINDOW_SECONDS = 3600;

    /** Why a recovery try is refused right now, or null when it may go ahead. */
    public static function blockedMessage(string $email, ?string $ip): ?string
    {
        if (RateLimiter::tooManyAttempts(self::ipKey($ip), self::IP_MAX_FAILURES)) {
            return 'Too many failed attempts from this computer. Try again in '.self::minutesLeft(self::ipKey($ip)).' minute(s).';
        }

        if (RateLimiter::tooManyAttempts(self::emailKey($email), self::MAX_FAILURES)) {
            return 'Too many failed attempts. Try again in '.self::minutesLeft(self::emailKey($email)).' minute(s).';
        }

        return null;
    }

    /**
     * A wrong set of answers or a wrong recovery code ($method: "questions"
     * or "recovery_code"). $account is null when the email is not an active
     * Super Admin.
     */
    public static function recordFailure(?Account $account, string $email, string $method, ?string $ip): void
    {
        $failures = RateLimiter::hit(self::emailKey($email), self::LOCKOUT_SECONDS);
        $ipFailures = RateLimiter::hit(self::ipKey($ip), self::IP_WINDOW_SECONDS);

        Audit::log('recovery.failed', 'account', $account?->id, ['method' => $method, 'email' => $email], actor: $email);

        if ($failures === self::MAX_FAILURES) {
            Audit::log('recovery.lockout', 'account', $account?->id,
                ['email' => $email, 'minutes' => self::LOCKOUT_SECONDS / 60], actor: $email);

            if ($account && ! $account->security_questions_disabled_at
                && self::recentLockouts($account->id) >= self::LOCKOUTS_TO_DISABLE) {
                $account->forceFill(['security_questions_disabled_at' => now()])->save();
                Audit::log('recovery.questions_disabled', 'account', $account->id, actor: $email);
            }
        }

        if ($ipFailures === self::IP_MAX_FAILURES) {
            Audit::log('recovery.ip_limited', details: ['ip' => $ip, 'minutes' => self::IP_WINDOW_SECONDS / 60], actor: $email);
        }
    }

    /** Correct answers or a valid code: the email's failure count starts over. */
    public static function recordSuccess(string $email): void
    {
        RateLimiter::clear(self::emailKey($email));
    }

    public static function questionsDisabled(Account $account): bool
    {
        return $account->security_questions_disabled_at !== null;
    }

    /**
     * After a password reset by recovery code or on the server: the old
     * questions are removed (new ones are required at next login), disabled
     * questions are re-enabled, and the lockout is cleared.
     */
    public static function afterReset(Account $account, string $actor): void
    {
        SecurityQuestions::clear($account->id);
        RateLimiter::clear(self::emailKey($account->email));

        if ($account->security_questions_disabled_at) {
            $account->forceFill(['security_questions_disabled_at' => null])->save();
            Audit::log('recovery.questions_reenabled', 'account', $account->id, actor: $actor);
        }
    }

    /**
     * Failed recovery tries on the account after $since (all of them when
     * null): how many, and when the latest was.
     *
     * @return array{count: int, latest: ?string}
     */
    public static function failuresSince(int $accountId, ?Carbon $since): array
    {
        $failures = DB::table('audit_logs')
            ->where('action', 'recovery.failed')
            ->where('subject_type', 'account')
            ->where('subject_id', $accountId)
            ->when($since, fn ($query) => $query->where('created_at', '>', $since));

        return ['count' => (clone $failures)->count(), 'latest' => $failures->max('created_at')];
    }

    private static function recentLockouts(int $accountId): int
    {
        return DB::table('audit_logs')
            ->where('action', 'recovery.lockout')
            ->where('subject_type', 'account')
            ->where('subject_id', $accountId)
            ->where('created_at', '>=', now()->subHours(self::LOCKOUT_COUNT_HOURS))
            ->count();
    }

    private static function emailKey(string $email): string
    {
        return 'forgot-answer:'.Str::lower(trim($email));
    }

    private static function ipKey(?string $ip): string
    {
        return 'forgot-ip:'.$ip;
    }

    private static function minutesLeft(string $key): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
    }
}
