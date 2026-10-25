<?php

namespace App\Domain\Attendance;

use App\Support\KioskHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Webcam photos the kiosk takes at the moment of each scan (accepted or
 * rejected), for the Super Admin to compare with the registered photo.
 *
 * Images are re-encoded with GD (which drops anything that is not pixels)
 * and stored on the private disk: storage/app/private/scan-photos/Y/m/d.
 * They are only ever served through an authorized route.
 */
class ScanPhotos
{
    /** Largest accepted upload (the kiosk sends ~40-80 KB JPEGs). */
    private const MAX_BYTES = 1_500_000;

    /** Days a photo is kept before `attendance:prune-scan-photos` deletes it. */
    public const RETENTION_DAYS = 30;

    /** How the barcode reached the kiosk; a typed one carries the manual_entry flag. */
    public const INPUT_SCANNED = 'scanned';

    public const INPUT_TYPED = 'typed';

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
     * Stores the photo (if any) and its scan_photos row. A missing or
     * unreadable photo still records the row, with path = null, so a scan
     * without a picture is visible to the reviewer. A photo too dark or of
     * one flat color carries the camera_blocked flag (and no face result).
     */
    public static function record(?string $dataUrl, array $scan, string $barcode, string $scannedAt, ?int $kioskDeviceId, string $inputMethod = self::INPUT_SCANNED, ?bool $faceDetected = null): ?int
    {
        [$path, $cameraBlocked] = self::storeImage($dataUrl, $scannedAt);

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

        ob_start();
        imagejpeg($image, null, 75);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $path = 'scan-photos/'.date('Y/m/d', strtotime($scannedAt)).'/'.Str::uuid().'.jpg';

        Storage::disk('local')->put($path, $jpeg);

        return [$path, $cameraBlocked];
    }

    /**
     * Deletes photos (files and rows) older than the retention period. Scans
     * from the current week are always kept, whatever $days is, because the
     * Principal's Scan Verification card counts them.
     */
    public static function prune(int $days = self::RETENTION_DAYS): int
    {
        $byAge = now()->subDays($days);
        $weekStart = ScanVerificationCounts::weekStart();
        $cutoff = ($byAge->lt($weekStart) ? $byAge : $weekStart)->toDateTimeString();
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
