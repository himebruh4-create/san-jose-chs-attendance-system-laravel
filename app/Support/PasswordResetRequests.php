<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * An Admin's or Principal's request for a new password: one pending row in
 * password_reset_requests, shown to the Super Admin on the dashboard
 * ("Password Reset Requests") until they reset it in Settings -> Account
 * Management. Filing a request never changes the password.
 *
 * Filed from Forgot Password (signed out) and from My Account -> Forgot
 * Password -> Request new password (signed in). Both answer with one
 * message whatever happened, so neither tells whether an account exists or
 * already has a request waiting.
 */
class PasswordResetRequests
{
    public const SENT = 'Your request was sent to the Super Admin.';

    /**
     * Files a pending request for an active Admin / Principal, unless one is
     * already pending. The account row is locked first, so two requests at
     * the same moment still leave one pending row.
     *
     * @return bool Whether a new request was filed.
     */
    public static function file(Account $account, string $from, ?string $actor = null): bool
    {
        if ($account->role === 'superadmin' || $account->is_deleted) {
            return false;
        }

        $filed = DB::transaction(function () use ($account) {
            DB::table('accounts')->where('id', $account->id)->lockForUpdate()->first(['id']);

            $alreadyPending = DB::table('password_reset_requests')
                ->where('account_id', $account->id)
                ->where('status', 'pending')
                ->exists();

            if ($alreadyPending) {
                return false;
            }

            DB::table('password_reset_requests')->insert(['account_id' => $account->id, 'status' => 'pending']);

            return true;
        });

        if ($filed) {
            Audit::log('password_reset.requested', 'account', $account->id, ['from' => $from], actor: $actor ?? $account->email);
        }

        return $filed;
    }
}
