<?php

namespace App\Models;

use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One login of an account on one device. Only one row per account is
 * active (not replaced, not ended); see App\Support\ActiveSessions.
 */
class AccountSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id', 'session_id', 'ip_address', 'user_agent', 'logged_in_at', 'last_seen_at',
        'replaced_at', 'replaced_by_id', 'can_reclaim', 'notified_at', 'ended_at', 'ended_reason',
    ];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'replaced_at' => 'datetime',
            'notified_at' => 'datetime',
            'ended_at' => 'datetime',
            'can_reclaim' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The login that took this session's place. */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** Neither replaced by another login nor ended. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('replaced_at')->whereNull('ended_at');
    }

    public function browser(): string
    {
        return UserAgent::browser((string) $this->user_agent);
    }

    public function operatingSystem(): string
    {
        return UserAgent::operatingSystem((string) $this->user_agent);
    }

    /**
     * What the prompt and the Audit Log say about this device.
     *
     * @return array{ip: ?string, browser: string, os: string}
     */
    public function device(): array
    {
        return ['ip' => $this->ip_address, 'browser' => $this->browser(), 'os' => $this->operatingSystem()];
    }
}
