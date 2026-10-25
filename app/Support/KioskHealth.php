<?php

namespace App\Support;

use App\Domain\Attendance\AttendanceData;
use App\Models\KioskAlert;
use App\Models\KioskDevice;
use GdImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kiosk camera health: the picture check, and the alerts the Super Admin
 * and Admin see (dashboard banner, sidebar count, Settings -> Kiosk Devices
 * history). A camera problem never stops a scan; it is only flagged and
 * alerted.
 *
 * The kiosk (public/js/kiosk.js, with the constants below from
 * kioskConfig()) checks its camera on every scan and every
 * CHECK_INTERVAL_MINUTES while idle, and reports "ok", "blocked",
 * "disconnected" or "denied" to POST /kiosk/health; that report is also its
 * check-in. The server checks each scan photo itself for the "Camera
 * blocked" flag. `kiosk:check-offline` (every minute) opens "Kiosk offline"
 * when a kiosk stops checking in during a scan window.
 *
 * Face detection runs in the kiosk's browser with MediaPipe Face Detection,
 * bundled under public/vendor/mediapipe for offline use (Apache-2.0):
 * - tasks-vision-0.10.21/: npm @mediapipe/tasks-vision@0.10.21 (tarball
 *   sha1 b78df074763e0c7fdf8606c60121d65c9199bbf6), only the ES module and
 *   the SIMD / non-SIMD WebAssembly builds.
 * - models/blaze_face_short_range.tflite: storage.googleapis.com/mediapipe-models/
 *   face_detector/blaze_face_short_range/float16/1/ (sha256
 *   b4578f35940bf5a1a655214a1cce5cab13eba73c1297cd78e1a04c2380b0152f).
 */
class KioskHealth
{
    /**
     * A picture is "blocked" when, shrunk to SAMPLE_WIDTH x SAMPLE_HEIGHT, its
     * average brightness (0-255) is below DARK_MEAN or its brightness hardly
     * varies (standard deviation below FLAT_STDDEV: one flat color, e.g. a
     * finger or tape on the lens). This kiosk's normal photos measure 72-185
     * average and 15-57 variation; covered-lens photos about 27 and 1.
     */
    public const DARK_MEAN = 35;

    public const FLAT_STDDEV = 5;

    public const SAMPLE_WIDTH = 32;

    public const SAMPLE_HEIGHT = 24;

    /** The kiosk checks its camera (and checks in) this often while idle. */
    public const CHECK_INTERVAL_MINUTES = 5;

    /** A blocked picture is checked again after this long before it is reported (someone leaning close is not a problem). */
    public const RECHECK_SECONDS = 30;

    /** A lost or refused camera is tried again this often. */
    public const CAMERA_RETRY_SECONDS = 30;

    /** Frames are not checked or photographed until the camera has run this long (exposure settling). */
    public const CAMERA_WARMUP_MS = 1500;

    /** No check-in for this long during a scan window opens "Kiosk offline". */
    public const OFFLINE_AFTER_MINUTES = 10;

    /** Scan window: this long before the day's earliest Time In until this long after its latest Time Out. */
    public const SCAN_WINDOW_MARGIN_MINUTES = 60;

    /** Kiosks not seen for this many days are taken as retired and get no new offline alerts. */
    public const RETIRED_AFTER_DAYS = 7;

    /** What the kiosk reports. "starting" (camera not answered yet) is a check-in only. */
    public const STATUSES = ['ok', 'blocked', 'disconnected', 'denied', 'starting'];

    public const FACE_DETECTOR_PATH = 'vendor/mediapipe/tasks-vision-0.10.21';

    public const FACE_MODEL_PATH = 'vendor/mediapipe/models/blaze_face_short_range.tflite';

    /** True when the image is too dark or one flat color (see DARK_MEAN). */
    public static function isBlocked(GdImage $image): bool
    {
        $sample = imagescale($image, self::SAMPLE_WIDTH, self::SAMPLE_HEIGHT, IMG_BILINEAR_FIXED);
        $sum = 0.0;
        $squares = 0.0;

        for ($x = 0; $x < self::SAMPLE_WIDTH; $x++) {
            for ($y = 0; $y < self::SAMPLE_HEIGHT; $y++) {
                $rgb = imagecolorat($sample, $x, $y);
                $luminance = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $sum += $luminance;
                $squares += $luminance * $luminance;
            }
        }

        imagedestroy($sample);

        $pixels = self::SAMPLE_WIDTH * self::SAMPLE_HEIGHT;
        $mean = $sum / $pixels;
        $stddev = sqrt(max(0, $squares / $pixels - $mean * $mean));

        return $mean < self::DARK_MEAN || $stddev < self::FLAT_STDDEV;
    }

    /**
     * A kiosk's report: it is online (an offline alert closes), and its
     * camera is "ok" (a camera alert closes) or has a problem (one alert is
     * opened for it, or kept if it is already open).
     */
    public static function report(KioskDevice $device, string $status): void
    {
        $actor = self::actor($device);

        DB::transaction(function () use ($device, $status, $actor) {
            $open = self::lockOpenAlert($device);

            if ($open?->type === 'offline') {
                self::close($open, 'online', $actor);
                $open = null;
            }

            if ($status === 'starting') {
                return;
            }

            if ($status === 'ok') {
                if ($open) {
                    self::close($open, 'fixed', $actor);
                }

                return;
            }

            if ($open?->type === $status) {
                return;
            }

            if ($open) {
                self::close($open, 'changed', $actor);
            }

            self::open($device, $status, now(), null, $actor);
        });
    }

    /**
     * Opens "Kiosk offline" for each active kiosk that has not checked in for
     * OFFLINE_AFTER_MINUTES, during a scan window, while attendance
     * recording is on. Its open camera alert (if any) is closed first.
     * Returns the number of alerts opened.
     */
    public static function checkOffline(?Carbon $now = null): int
    {
        $now ??= now();

        if (! (bool) DB::table('system_settings')->where('id', 1)->value('attendance_recording_enabled')) {
            return 0;
        }

        $window = self::currentScanWindow($now);
        if (! $window) {
            return 0;
        }

        $devices = KioskDevice::query()
            ->whereNull('revoked_at')
            ->where('last_seen_at', '<', $now->copy()->subMinutes(self::OFFLINE_AFTER_MINUTES))
            ->where('last_seen_at', '>=', $now->copy()->subDays(self::RETIRED_AFTER_DAYS))
            ->get();

        $opened = 0;

        foreach ($devices as $device) {
            DB::transaction(function () use ($device, $window, &$opened) {
                $open = self::lockOpenAlert($device);

                if ($open?->type === 'offline') {
                    return;
                }

                if ($open) {
                    self::close($open, 'offline', 'system');
                }

                $since = $device->last_seen_at->max($window[0]);
                self::open($device, 'offline', $since, $device->last_seen_at, 'system');
                $opened++;
            });
        }

        return $opened;
    }

    /** Closes a revoked kiosk's open alert. */
    public static function closeForRevokedKiosk(KioskDevice $device): void
    {
        DB::transaction(function () use ($device) {
            if ($open = self::lockOpenAlert($device)) {
                self::close($open, 'revoked', auth()->user()?->email ?? 'system');
            }
        });
    }

    /**
     * The scan window $now falls in, if any: yesterday's (an overnight
     * shift reaching into today) or today's.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function currentScanWindow(Carbon $now): ?array
    {
        foreach ([$now->copy()->subDay(), $now->copy()] as $day) {
            $window = self::scanWindow($day);

            if ($window && $now->between($window[0], $window[1])) {
                return $window;
            }
        }

        return null;
    }

    /**
     * One day's scan window: SCAN_WINDOW_MARGIN_MINUTES before the earliest
     * Time In of the personnel expected that day until the same margin
     * after their latest Time Out (a Time Out at or before the Time In ends
     * the next day). People off for a whole-day school event (not among its
     * workers) are not expected; a day nobody is expected has no window.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function scanWindow(Carbon $day): ?array
    {
        $date = $day->format('Y-m-d');

        // Workers of each whole-day event on this day.
        $eventWorkers = [];
        foreach (AttendanceData::activeEvents() as $event) {
            if (($event['duration'] ?? '') !== 'Half Day' && $date >= $event['date_from'] && $date <= $event['date_to']) {
                $eventWorkers[] = $event['worker_ids'];
            }
        }

        $schedules = DB::table('teacher_schedules as s')
            ->join('teachers as t', 't.id', '=', 's.teacher_id')
            ->where('t.is_deleted', 0)
            ->where('s.day', $day->format('l'))
            ->get(['s.teacher_id', 's.time_in', 's.time_out']);

        $start = null;
        $end = null;

        foreach ($schedules as $schedule) {
            $offForAnEvent = array_filter($eventWorkers, fn (array $workers) => ! in_array((int) $schedule->teacher_id, $workers, true));
            if ($offForAnEvent) {
                continue;
            }

            $timeIn = Carbon::parse("{$date} {$schedule->time_in}");
            $timeOut = Carbon::parse("{$date} {$schedule->time_out}");
            if ($timeOut->lte($timeIn)) {
                $timeOut->addDay();
            }

            $start = $start ? $start->min($timeIn) : $timeIn;
            $end = $end ? $end->max($timeOut) : $timeOut;
        }

        if (! $start) {
            return null;
        }

        return [
            $start->copy()->subMinutes(self::SCAN_WINDOW_MARGIN_MINUTES),
            $end->copy()->addMinutes(self::SCAN_WINDOW_MARGIN_MINUTES),
        ];
    }

    /**
     * Open alerts of active kiosks, oldest first (dashboard banner).
     *
     * @return Collection<int, KioskAlert>
     */
    public static function openAlerts(): Collection
    {
        return KioskAlert::query()->open()
            ->whereHas('kiosk', fn ($query) => $query->whereNull('revoked_at'))
            ->with('kiosk:id,name')
            ->orderBy('started_at')
            ->get();
    }

    /** The sidebar count. */
    public static function openCount(): int
    {
        return KioskAlert::query()->open()->whereHas('kiosk', fn ($query) => $query->whereNull('revoked_at'))->count();
    }

    /**
     * Alerts that started in the last $days days, newest first (Settings ->
     * Kiosk Devices).
     *
     * @return Collection<int, KioskAlert>
     */
    public static function history(int $days = 30): Collection
    {
        return KioskAlert::query()
            ->where('started_at', '>=', now()->subDays($days))
            ->with('kiosk:id,name')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What public/js/kiosk.js needs.
     *
     * @return array<string, mixed>
     */
    public static function kioskConfig(): array
    {
        return [
            'darkMean' => self::DARK_MEAN,
            'flatStddev' => self::FLAT_STDDEV,
            'sampleWidth' => self::SAMPLE_WIDTH,
            'sampleHeight' => self::SAMPLE_HEIGHT,
            'checkIntervalMs' => self::CHECK_INTERVAL_MINUTES * 60_000,
            'recheckMs' => self::RECHECK_SECONDS * 1000,
            'retryMs' => self::CAMERA_RETRY_SECONDS * 1000,
            'warmupMs' => self::CAMERA_WARMUP_MS,
            'healthUrl' => route('kiosk.health'),
            'faceDetector' => [
                'module' => asset(self::FACE_DETECTOR_PATH.'/vision_bundle.mjs'),
                'wasm' => asset(self::FACE_DETECTOR_PATH.'/wasm'),
                'model' => asset(self::FACE_MODEL_PATH),
            ],
        ];
    }

    private static function lockOpenAlert(KioskDevice $device): ?KioskAlert
    {
        KioskDevice::query()->whereKey($device->id)->lockForUpdate()->first();

        return KioskAlert::query()->where('open_device_id', $device->id)->first();
    }

    private static function open(KioskDevice $device, string $type, Carbon $since, ?Carbon $lastSeenAt, string $actor): KioskAlert
    {
        $alert = KioskAlert::create([
            'kiosk_device_id' => $device->id,
            'type' => $type,
            'started_at' => $since,
            'last_seen_at' => $lastSeenAt,
            'open_device_id' => $device->id,
        ]);

        Audit::log('kiosk_alert.opened', 'kiosk_device', $device->id, [
            'kiosk' => $device->name,
            'alert' => $alert->message(),
        ], $actor);

        return $alert;
    }

    private static function close(KioskAlert $alert, string $resolution, string $actor): void
    {
        $alert->forceFill(['resolved_at' => now(), 'resolution' => $resolution, 'open_device_id' => null])->save();

        Audit::log('kiosk_alert.closed', 'kiosk_device', $alert->kiosk_device_id, [
            'kiosk' => $alert->kiosk?->name,
            'alert' => $alert->message(),
            'closed' => KioskAlert::RESOLUTIONS[$resolution] ?? $resolution,
        ], $actor);
    }

    private static function actor(KioskDevice $device): string
    {
        return mb_substr('kiosk: '.$device->name, 0, 150);
    }
}
