<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A personnel record (teaching or non-teaching staff). The table keeps its
 * legacy name, `teachers`.
 */
class Teacher extends Model
{
    protected $fillable = [
        'id_number', 'fullname', 'department', 'academic_status',
        'employment_type', 'barcode', 'photo',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_deleted', false);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TeacherSchedule::class);
    }

    /** Next "YYYY-NNNN" ID number for the current year. */
    public static function nextIdNumber(): string
    {
        $year = date('Y');

        $last = static::query()
            ->where('id_number', 'like', $year.'-%')
            ->orderByDesc('id')
            ->value('id_number');

        $next = $last ? ((int) substr($last, 5)) + 1 : 1;

        return $year.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** A random 6-digit barcode no one else has. */
    public static function newBarcode(): string
    {
        do {
            $barcode = (string) random_int(100000, 999999);
        } while (static::query()->where('barcode', $barcode)->exists());

        return $barcode;
    }
}
