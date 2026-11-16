<?php

namespace App\Domain\Attendance;

use App\Support\KioskHealth;
use GdImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Webcam photos of arrivals (Time In and Lunch In), for the Super Admin to
 * compare with the registered photo. Every scan the kiosk reports still
 * gets a scan_photos row (for its flags), but a departure (Time Out, Lunch
 * Out) or a rejected scan keeps no photo, even when the kiosk sent one. An
 * unknown barcode is kept as text in the Rejected Scan Log instead
 * (Scanner).
 *
 * Images are resized to at most PHOTO_MAX_WIDTH wide, re-encoded with GD
 * (which drops anything that is not pixels) as low-quality WebP (JPEG where
 * GD has no WebP) and stored on the private disk:
 * storage/app/private/scan-photos/Y/m/d. They are only ever served through
 * an authorized route, each with its own type (older photos are JPEG).
 */
class ScanPhotos
{
    /** Largest accepted upload (the kiosk sends ~5-15 KB WebP or JPEG). */
    private const MAX_BYTES = 1_500_000;

    /** Days a photo is kept before `attendance:prune-scan-photos` deletes it. */
    public const RETENTION_DAYS = 30;

    /** When `attendance:prune-scan-photos` runs each day (routes/console.php). */
    public const PRUNE_TIME = '02:00';

    /** The Super Admin dashboard warns this many days before photos are deleted. */
    public const DELETION_WARNING_DAYS = 3;

    /** How the barcode reached the kiosk; a typed one carries the manual_entry flag. */
    public const INPUT_SCANNED = 'scanned';

    public const INPUT_TYPED = 'typed';

    /** Scan kinds (Scanner `kind`) that keep a photo: the arrivals. */
    public const PHOTO_KINDS = ['IN', 'BREAK_IN'];

    /** Stored photos are at most this wide (the kiosk already sends 320 px). */
    public const PHOTO_MAX_WIDTH = 320;

    /** Low, but a face is still clear at 320 px. */
    private const WEBP_QUALITY = 50;

    private const JPEG_QUALITY = 60;

    /** Stored file extension => the type it is served with. */
    private const CONTENT_TYPES = [
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /**
     * The kiosk's reported input method. Anything other than "typed",
     * including nothing at all, counts as scanned so a broken client never
     * blocks attendance.
     */
    public static function inputMethod(mixed $value): string
    {
        return $value === self::INPUT_TYPED ? self::INPUT_TYPED : self::INPUT_SCANNED;
    }

    /**
     * The kiosk's face detection result: "1" a face, "0" none (the "No face
     * detected" flag), anything else not checked.
     */
    public static function faceDetected(mixed $value): ?bool
    {
        return match ($value) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    /**
     * True for an accepted Time In or Lunch In, the only scans that keep a photo.
     *
     * @param  array{outcome?: string, kind?: string}  $scan
     */
    public static function takesPhoto(array $scan): bool
    {
        return ($scan['outcome'] ?? null) === 'accepted' && in_array($scan['kind'] ?? null, self::PHOTO_KINDS, true);
    }

    /** The type a stored photo is served with, from its extension. */
    public static function contentType(string $path): string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'image/jpeg';
    }

    /**
     * Stores the photo (arrivals only) and the scan_photos row. A missing or
     * unreadable photo of an arrival still records the row, with path =
     * null, so an arrival without a picture is visible to the reviewer. A
     * photo too dark or of one flat color carries the camera_blocked flag
     * (and no face result).
     */
    public static function record(?string $dataUrl, array $scan, string $barcode, string $scannedAt, ?int $kioskDeviceId, string $inputMethod = self::INPUT_SCANNED, ?bool $faceDetected = null): ?int
    {
        [$path, $cameraBlocked] = self::takesPhoto($scan) ? self::storeImage($dataUrl, $scannedAt) : [null, false];

        return DB::table('scan_photos')->insertGetId([
            'teacher_id' => $scan['teacher_id'] ?? null,
            // The typed barcode is only kept when it matched nobody.
            'barcode' => isset($scan['teacher_id']) ? null : mb_substr($barcode, 0, 100),
            'attendance_date' => $scan['attendance_date'] ?? substr($scannedAt, 0, 10),
            'scanned_at' => $scannedAt,
            'outcome' => $scan['outcome'] ?? ($scan['success'] ? 'accepted' : 'rejected'),
            'scan_kind' => $scan['kind'] ?? null,
            'input_method' => self::inputMethod($inputMethod),
            'path' => $path,
            'camera_blocked' => $cameraBlocked,
            'face_detected' => $path && ! $cameraBlocked ? $faceDetected : null,
            'kiosk_device_id' => $kioskDeviceId,
        ]);
    }

    /**
     * @return array{0: ?string, 1: bool} [stored path or null, camera blocked]
     */
    private static function storeImage(?string $dataUrl, string $scannedAt): array
    {
        if (! is_string($dataUrl) || ! str_starts_with($dataUrl, 'data:image/')) {
            return [null, false];
        }

        $comma = strpos($dataUrl, ',');
        if ($comma === false) {
            return [null, false];
        }

        $bytes = base64_decode(substr($dataUrl, $comma + 1), true);

        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || ! @getimagesizefromstring($bytes)) {
            return [null, false];
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return [null, false];
        }

        $cameraBlocked = KioskHealth::isBlocked($image);

        [$encoded, $extension] = self::encodeSmall($image);

        $path = 'scan-photos/'.date('Y/m/d', strtotime($scannedAt)).'/'.Str::uuid().'.'.$extension;

        Storage::disk('local')->put($path, $encoded);

        return [$path, $cameraBlocked];
    }

    /**
     * Resizes to at most PHOTO_MAX_WIDTH wide (keeping the aspect ratio) and
     * encodes as low-quality WebP, or JPEG where GD has no WebP support.
     *
     * @return array{0: string, 1: string} [bytes, file extension]
     */
    private static function encodeSmall(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::PHOTO_MAX_WIDTH) {
            $newHeight = max(1, (int) round($height * self::PHOTO_MAX_WIDTH / $width));
            $resized = imagecreatetruecolor(self::PHOTO_MAX_WIDTH, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, self::PHOTO_MAX_WIDTH, $newHeight, $width, $height);
            $image = $resized;
        }

        $webp = function_exists('imagewebp');

        ob_start();
        if ($webp) {
            imagewebp($image, null, self::WEBP_QUALITY);
        } else {
            imagejpeg($image, null, self::JPEG_QUALITY);
        }

        return [ob_get_clean(), $webp ? 'webp' : 'jpg'];
    }

    /**
     * The prune run at $at deletes every scan before this moment: $days old,
     * but never from the week $at is in (Monday 00:00, Asia/Manila).
     */
    public static function cutoffAt(Carbon $at, int $days = self::RETENTION_DAYS): string
    {
        $byAge = $at->copy()->subDays($days);
        $weekStart = $at->copy()->setTimezone(ScanVerificationCounts::TIMEZONE)->startOfWeek(Carbon::MONDAY);

        return ($byAge->lt($weekStart) ? $byAge : $weekStart)->toDateTimeString();
    }

    /**
     * For the Super Admin dashboard's warning: how many stored photos the
     * daily prune (PRUNE_TIME, RETENTION_DAYS) deletes within the next $days
     * days, and its next run that deletes any, with how many. Nothing is
     * deleted here.
     *
     * @return array{count: int, next_deletion_at: ?Carbon, next_deletion_count: int}
     */
    public static function upcomingDeletion(int $days = self::DELETION_WARNING_DAYS): array
    {
        $now = now();
        $windowEnd = $now->copy()->addDays($days);

        $run = $now->copy()->setTimeFromTimeString(self::PRUNE_TIME);
        if ($run->lte($now)) {
            $run->addDay();
        }

        $photos = DB::table('scan_photos')->whereNotNull('path');
        $lastCutoff = null;
        $nextDeletionAt = null;
        $nextDeletionCount = 0;

        for (; $run->lte($windowEnd); $run->addDay()) {
            $lastCutoff = self::cutoffAt($run);

            if ($nextDeletionAt === null) {
                $nextDeletionCount = (clone $photos)->where('scanned_at', '<', $lastCutoff)->count();
                $nextDeletionAt = $nextDeletionCount > 0 ? $run->copy() : null;
            }
        }

        return [
            'count' => $lastCutoff === null ? 0 : (clone $photos)->where('scanned_at', '<', $lastCutoff)->count(),
            'next_deletion_at' => $nextDeletionAt,
            'next_deletion_count' => $nextDeletionCount,
        ];
    }

    /**
     * Deletes photos (files and rows) older than the retention period. Scans
     * from the current week are always kept, whatever $days is, because the
     * Principal's Scan Verification card counts them.
     */
    public static function prune(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = self::cutoffAt(now(), $days);
        $deleted = 0;

        DB::table('scan_photos')->where('scanned_at', '<', $cutoff)->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted) {
                foreach ($rows as $row) {
                    if ($row->path) {
                        Storage::disk('local')->delete($row->path);
                    }
                }
                $deleted += DB::table('scan_photos')->whereIn('id', $rows->pluck('id'))->delete();
            });

        return $deleted;
    }
}
