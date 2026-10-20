<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * The "Just now" kiosk photos behind a report row's four scans, for the
 * Super Admin's photo strip on the Reports page. Super Admin only: callers
 * must not build it for any other role (RA 10173), and the URLs point at
 * the Super Admin-only photos.scan route.
 *
 * A scan photo belongs to the attendance column it filled: the kiosk saves
 * both with the same scan time, and the photo keeps the attendance date of
 * the shift (so an overnight time-out still matches its own row).
 */
class ScanPhotoStrip
{
    /** Attendance column => label, in strip order. */
    public const SLOTS = [
        'am_arrival' => 'AM Arrival',
        'am_departure' => 'AM Departure',
        'pm_arrival' => 'PM Arrival',
        'pm_departure' => 'PM Departure',
    ];

    /**
     * Adds a `photos` strip to every report row.
     *
     * A slot with no scan has time = null; a scan without a photo (deleted
     * after 30 days, camera off, or an entry that never went through the
     * kiosk) has time set and url = null.
     *
     * @param  list<array{teacher_id: int, date: string, am_arrival: ?string, am_departure: ?string, pm_arrival: ?string, pm_departure: ?string}>  $rows
     * @return list<array<string, mixed>> the same rows, each with photos: list<array{label: string, time: ?string, url: ?string}>
     */
    public static function attach(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $dates = array_column($rows, 'date');
        $photoIds = [];

        DB::table('scan_photos')
            ->whereIn('teacher_id', array_unique(array_column($rows, 'teacher_id')))
            ->whereBetween('attendance_date', [min($dates), max($dates)])
            ->where('outcome', 'accepted')
            ->orderBy('id')
            ->get(['id', 'teacher_id', 'attendance_date', 'scanned_at', 'path'])
            ->each(function ($photo) use (&$photoIds) {
                $key = self::key((int) $photo->teacher_id, $photo->attendance_date, substr($photo->scanned_at, 11, 8));
                $photoIds[$key] = $photo->path ? (int) $photo->id : null;
            });

        foreach ($rows as &$row) {
            $row['photos'] = [];

            foreach (self::SLOTS as $column => $label) {
                $time = $row[$column] ?? null;
                $photoId = $time ? ($photoIds[self::key((int) $row['teacher_id'], $row['date'], $time)] ?? null) : null;

                $row['photos'][] = [
                    'label' => $label,
                    'time' => $time,
                    'url' => $photoId ? route('photos.scan', $photoId) : null,
                ];
            }
        }

        return $rows;
    }

    private static function key(int $teacherId, string $date, string $time): string
    {
        return $teacherId.'|'.$date.'|'.$time;
    }
}
