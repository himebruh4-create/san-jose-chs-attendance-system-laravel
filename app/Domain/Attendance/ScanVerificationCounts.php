<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counts behind the Principal's read-only "Scan Verification" card. Counts
 * only: no names, ids or photo paths ever leave here (scan photos are
 * personal data that only the Super Admin may see).
 *
 * Until scans carry their own verification flags, a scan is flagged when it
 * is a repeat (duplicate / already complete), an unknown barcode (not found /
 * invalid), has no photo, was a manual entry (barcode typed, not scanned), or
 * was a rapid scan (see rapidScanCondition()). A scan can have more than one flag.
 * "Waiting for review" is the Super Admin's unreviewed Rejected Scan Log.
 */
class ScanVerificationCounts
{
    public const TIMEZONE = 'Asia/Manila';

    private const REPEAT = ['duplicate', 'complete'];

    private const UNKNOWN = ['not_found', 'invalid'];

    /** Accepted scans of different people on one kiosk this close together are both flagged as rapid. */
    public const RAPID_SECONDS = 5;

    /**
     * SQL condition, true when the scan_photos row aliased $alias is a rapid
     * scan: it was accepted, and an accepted scan of a different person on
     * the same kiosk happened within RAPID_SECONDS before or after it. This
     * is the pattern of one person scanning several badges, so both scans are
     * flagged; people scanning at the same moment at different kiosks are not.
     * A scan whose kiosk was deleted (kiosk_device_id NULL) is never rapid.
     * $alias is always a fixed name from this codebase, never user input.
     */
    public static function rapidScanCondition(string $alias): string
    {
        $seconds = self::RAPID_SECONDS;

        return "({$alias}.outcome = 'accepted' AND EXISTS (
            SELECT 1 FROM scan_photos AS rapid_other
            WHERE rapid_other.outcome = 'accepted'
              AND rapid_other.teacher_id <> {$alias}.teacher_id
              AND rapid_other.kiosk_device_id = {$alias}.kiosk_device_id
              AND rapid_other.scanned_at BETWEEN {$alias}.scanned_at - INTERVAL {$seconds} SECOND
                                             AND {$alias}.scanned_at + INTERVAL {$seconds} SECOND))";
    }

    /** Monday 00:00 of the current week: the start of everything the card counts. */
    public static function weekStart(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfWeek(Carbon::MONDAY);
    }

    /**
     * @return array{flagged_week: int, repeat_week: int, rapid_week: int, unknown_week: int, nophoto_week: int, manual_week: int, unreviewed: int, loaded_at: string}
     */
    public static function get(): array
    {
        $now = Carbon::now(self::TIMEZONE);
        $weekStart = self::weekStart()->toDateTimeString();

        $repeat = "p.outcome IN ('".implode("','", self::REPEAT)."')";
        $rapid = self::rapidScanCondition('p');
        $unknown = "p.outcome IN ('".implode("','", self::UNKNOWN)."')";
        $noPhoto = 'p.path IS NULL';
        $manual = "p.input_method = '".ScanPhotos::INPUT_TYPED."'";

        $row = DB::table('scan_photos as p')
            ->where('p.scanned_at', '>=', $weekStart)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$repeat} OR {$rapid} OR {$unknown} OR {$noPhoto} OR {$manual} THEN 1 ELSE 0 END), 0) AS flagged_week")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$repeat} THEN 1 ELSE 0 END), 0) AS repeat_week")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$rapid} THEN 1 ELSE 0 END), 0) AS rapid_week")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$unknown} THEN 1 ELSE 0 END), 0) AS unknown_week")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$noPhoto} THEN 1 ELSE 0 END), 0) AS nophoto_week")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$manual} THEN 1 ELSE 0 END), 0) AS manual_week")
            ->selectRaw('(SELECT COUNT(*) FROM attendance_rejected_scans WHERE reviewed_at IS NULL) AS unreviewed')
            ->first();

        return [
            'flagged_week' => (int) $row->flagged_week,
            'repeat_week' => (int) $row->repeat_week,
            'rapid_week' => (int) $row->rapid_week,
            'unknown_week' => (int) $row->unknown_week,
            'nophoto_week' => (int) $row->nophoto_week,
            'manual_week' => (int) $row->manual_week,
            'unreviewed' => (int) $row->unreviewed,
            'loaded_at' => $now->toIso8601String(),
        ];
    }
}
