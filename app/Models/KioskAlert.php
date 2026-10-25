<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kiosk problem the Super Admin and Admin are alerted to: the camera is
 * blocked / too dark, disconnected or not allowed, or the kiosk stopped
 * checking in during a scan window. Text only, never a photo. At most one
 * open alert per kiosk (open_device_id is unique); opened and closed by
 * App\Support\KioskHealth.
 */
class KioskAlert extends Model
{
    public const UPDATED_AT = null;

    /** Alert type => what the banner says before "since ...". */
    public const TYPES = [
        'blocked' => 'Kiosk camera blocked or too dark',
        'disconnected' => 'Kiosk camera disconnected',
        'denied' => 'Kiosk camera not allowed (permission denied)',
        'offline' => 'Kiosk offline',
    ];

    /** How an alert was closed => what the history says. */
    public const RESOLUTIONS = [
        'fixed' => 'Camera working again',
        'online' => 'Kiosk back online',
        'changed' => 'Replaced by a new problem',
        'offline' => 'Closed: kiosk went offline',
        'revoked' => 'Closed: kiosk revoked',
    ];

    protected $fillable = ['kiosk_device_id', 'type', 'started_at', 'last_seen_at', 'resolved_at', 'resolution', 'open_device_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'kiosk_device_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('open_device_id');
    }

    /** "Kiosk camera blocked or too dark since 9:14 AM" (with the date when not today). */
    public function message(): string
    {
        $message = (self::TYPES[$this->type] ?? 'Kiosk problem').' since '.self::when($this->started_at);

        if ($this->type === 'offline' && $this->last_seen_at && ! $this->last_seen_at->eq($this->started_at)) {
            $message .= ' (last check-in '.self::when($this->last_seen_at).')';
        }

        return $message;
    }

    /** "9:14 AM" today, "Oct 24, 5:40 PM" otherwise. */
    public static function when(\DateTimeInterface $at): string
    {
        return $at->format('Y-m-d') === now()->format('Y-m-d') ? $at->format('g:i A') : $at->format('M j, g:i A');
    }
}
