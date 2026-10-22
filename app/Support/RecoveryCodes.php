<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-time recovery codes for the Super Admin's Forgot Password. Shown once
 * when generated (Settings -> Account Management), stored only as bcrypt
 * hashes, and each one works a single time.
 */
class RecoveryCodes
{
    /** Codes in a set. */
    public const COUNT = 10;

    /** Settings warns when this many or fewer are left. */
    public const LOW = 2;

    /** No 0/O or 1/I, so a printed code cannot be misread. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Replaces the account's codes with a new set and returns them, the
     * only time they can be seen.
     *
     * @return list<string> codes as XXXXX-XXXXX
     */
    public static function generate(int $accountId): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = '';
            for ($c = 0; $c < 10; $c++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        DB::transaction(function () use ($accountId, $codes) {
            DB::table('account_recovery_codes')->where('account_id', $accountId)->delete();

            foreach ($codes as $code) {
                DB::table('account_recovery_codes')->insert([
                    'account_id' => $accountId,
                    'code_hash' => Hash::make(self::normalize($code)),
                ]);
            }
        });

        return $codes;
    }

    /** Uppercase, letters and digits only ("k7qm2 hx9tp" = "K7QM2-HX9TP"). */
    public static function normalize(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', Str::upper($code));
    }

    /**
     * Uses one of the account's unused codes. The used_at update only
     * succeeds once, so the same code cannot be used twice even when two
     * requests arrive together.
     */
    public static function consume(int $accountId, string $code): bool
    {
        $code = self::normalize($code);

        if (strlen($code) !== 10) {
            return false;
        }

        $unused = DB::table('account_recovery_codes')->where('account_id', $accountId)
            ->whereNull('used_at')->get(['id', 'code_hash']);

        foreach ($unused as $row) {
            if (Hash::check($code, $row->code_hash)) {
                return DB::table('account_recovery_codes')->where('id', $row->id)->whereNull('used_at')
                    ->update(['used_at' => now()]) === 1;
            }
        }

        return false;
    }

    public static function remaining(int $accountId): int
    {
        return DB::table('account_recovery_codes')->where('account_id', $accountId)->whereNull('used_at')->count();
    }

    /** When the current set was generated, or null if there is none. */
    public static function generatedAt(int $accountId): ?string
    {
        return DB::table('account_recovery_codes')->where('account_id', $accountId)->max('created_at');
    }

    /** Deletes all of the account's codes; returns how many there were. */
    public static function revoke(int $accountId): int
    {
        return DB::table('account_recovery_codes')->where('account_id', $accountId)->delete();
    }
}
