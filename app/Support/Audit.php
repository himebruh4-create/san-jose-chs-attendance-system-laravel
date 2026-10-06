<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes the audit trail (audit_logs): who changed what, when, from where.
 */
class Audit
{
    public static function log(string $action, ?string $subjectType = null, ?int $subjectId = null, array $details = [], ?string $actor = null): void
    {
        $account = Auth::user();

        DB::table('audit_logs')->insert([
            'account_id' => $account?->id,
            'actor' => $actor ?? $account?->email ?? 'system',
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => $details ? json_encode($details) : null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
