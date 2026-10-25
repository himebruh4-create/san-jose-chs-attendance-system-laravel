<?php

namespace App\Http\Controllers;

use App\Domain\Attendance\Scanner;
use App\Domain\Attendance\ScanPhotos;
use App\Support\KioskHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * The attendance kiosk (was teacher-dashboard.php and save-attendance.php).
 * Every route here requires an authorized kiosk device.
 *
 * The kiosk shows no attendance records: the native-PHP page listed
 * everyone's scans for the day, and get-dtr.php let the person who had just
 * scanned open their weekly DTR. Both are gone; DTRs are on the staff pages.
 */
class KioskController extends Controller
{
    public function index(): View
    {
        return view('kiosk.index');
    }

    public function scan(Request $request, Scanner $scanner)
    {
        $device = $request->attributes->get('kiosk_device');
        $barcode = substr(trim((string) $request->input('barcode')), 0, 100);

        // A real person scans a few times a day; this stops scripted guessing
        // of 6-digit barcodes even from an authorized kiosk.
        $missKey = 'kiosk-miss:'.$device->id;
        if (RateLimiter::tooManyAttempts($missKey, 10)) {
            return response()->json([
                'success' => false,
                'title' => 'Please Wait',
                'message' => 'Too many unrecognized barcodes. Scanning resumes in '.RateLimiter::availableIn($missKey).' seconds.',
            ], 429);
        }

        $now = now()->format('Y-m-d H:i:s');
        $result = $scanner->scan($barcode, $now);

        if (($result['outcome'] ?? null) === 'not_found') {
            RateLimiter::hit($missKey, 60);
        }

        // Recorded after the attendance rules have decided, and never allowed
        // to fail the scan: the attendance (if any) is already saved.
        if (in_array($result['outcome'] ?? null, ['accepted', 'duplicate', 'complete', 'not_found'], true)) {
            $inputMethod = ScanPhotos::inputMethod($request->input('input_method'));

            try {
                ScanPhotos::record($request->input('photo'), $result, $barcode, $now, $device->id, $inputMethod,
                    ScanPhotos::faceDetected($request->input('face_detected')));
            } catch (Throwable $e) {
                Log::error('Kiosk scan photo/flags could not be recorded.', [
                    'teacher_id' => $result['teacher_id'] ?? null,
                    'outcome' => $result['outcome'],
                    'input_method' => $inputMethod,
                    'kiosk_device_id' => $device->id,
                    'exception' => $e,
                ]);
            }
        }

        unset($result['outcome'], $result['attendance_date']);

        return response()->json($result);
    }

    /**
     * The kiosk's camera check (every few minutes, and when its camera
     * changes): opens or closes this kiosk's alert. Also its check-in, so
     * an offline alert closes. Text only; no photo is ever sent here.
     */
    public function health(Request $request): JsonResponse
    {
        $data = $request->validate([
            'camera' => ['required', 'string', Rule::in(KioskHealth::STATUSES)],
        ]);

        KioskHealth::report($request->attributes->get('kiosk_device'), $data['camera']);

        return $this->ok();
    }
}
