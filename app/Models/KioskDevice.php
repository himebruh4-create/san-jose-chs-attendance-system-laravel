<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A browser the Super Admin authorized to record scans. The browser holds
 * the plain token in an HttpOnly cookie; only its SHA-256 is stored.
 */
class KioskDevice extends Model
{
    public const COOKIE = 'sjchs_kiosk';

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'token_hash', 'created_by'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Creates a device and returns [device, plain token]. */
    public static function register(string $name, string $createdBy): array
    {
        $token = Str::random(64);

        $device = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'created_by' => $createdBy,
        ]);

        return [$device, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();
    }
}
