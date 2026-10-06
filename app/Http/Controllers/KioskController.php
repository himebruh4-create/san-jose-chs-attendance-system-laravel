<?php

namespace App\Http\Controllers;

use App\Domain\Attendance\Scanner;
use App\Domain\Attendance\ScanPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The attendance kiosk (was teacher-dashboard.php, save-attendance.php and
 * get-dtr.php). Every route here requires an authorized kiosk device.
 */
class KioskController extends Controller
{
    /** Seconds after a scan during which that person may open their weekly DTR. */
    private const DTR_WINDOW = 120;

    public function index()
    {
        $today = date('Y-m-d');

        $rows = DB::table('attendance as a')
            ->join('teachers as t', 't.id', '=', 'a.teacher_id')
            ->leftJoin('teacher_schedules as ts', function ($join) {
                $join->on('ts.teacher_id', '=', 'a.teacher_id')
                    ->whereRaw('LOWER(ts.day) = LOWER(DAYNAME(a.date))');
            })
            ->where('a.date', $today)
            ->orderByRaw("GREATEST(
                COALESCE(a.am_arrival, '00:00:00'), COALESCE(a.am_departure, '00:00:00'),
                COALESCE(a.pm_arrival, '00:00:00'), COALESCE(a.pm_departure, '00:00:00')) DESC")
            ->get(['a.teacher_id', 'a.date', 'a.am_arrival', 'a.am_departure', 'a.pm_arrival', 'a.pm_departure',
                't.fullname', 'ts.time_in as sched_in'])
            ->map(function ($row) {
                // Lateness is judged against this person's own scheduled time
                // in (no grace); only the first arrival of the shift counts.
                // Fixed 8:00 / 1:00 PM cutoffs apply only when no schedule exists.
                $arrivals = array_values(array_filter([$row->am_arrival, $row->pm_arrival]));
                sort($arrivals);
                $firstIn = $arrivals[0] ?? null;

                $status = function ($time, $legacyCutoff) use ($row, $firstIn) {
                    if (! $time) {
                        return '-';
                    }
                    if ($row->sched_in) {
                        if ($time !== $firstIn) {
                            return '-';
                        }

                        return strtotime($time) <= strtotime($row->sched_in) ? 'On-Time' : 'Late';
                    }

                    return strtotime($time) <= strtotime($legacyCutoff) ? 'On-Time' : 'Late';
                };

                $fmt = fn ($t) => $t ? date('h:i A', strtotime($t)) : '-';

                return [
                    'teacher_id' => (int) $row->teacher_id,
                    'fullname' => $row->fullname,
                    'date' => date('M d, Y', strtotime($row->date)),
                    'am_arrival' => $fmt($row->am_arrival),
                    'am_departure' => $fmt($row->am_departure),
                    'pm_arrival' => $fmt($row->pm_arrival),
                    'pm_departure' => $fmt($row->pm_departure),
                    'am_status' => $status($row->am_arrival, '08:00:00'),
                    'pm_status' => $status($row->pm_arrival, '13:00:00'),
                ];
            });

        return view('kiosk.index', ['rows' => $rows]);
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

        $now = date('Y-m-d H:i:s');
        $result = $scanner->scan($barcode, $now);

        if (($result['outcome'] ?? null) === 'not_found') {
            RateLimiter::hit($missKey, 60);
        }

        if (in_array($result['outcome'] ?? null, ['accepted', 'duplicate', 'complete', 'not_found'], true)) {
            ScanPhotos::record($request->input('photo'), $result, $barcode, $now, $device->id);
        }

        if (! empty($result['teacher_id'])) {
            // Only the person who just scanned may open their weekly DTR.
            $request->session()->put('kiosk_dtr', ['teacher_id' => $result['teacher_id'], 'until' => time() + self::DTR_WINDOW]);
        }

        unset($result['outcome'], $result['attendance_date']);

        return response()->json($result);
    }

    /** The current week's scans of the person who just scanned (was get-dtr.php). */
    public function weeklyDtr(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');
        $grant = $request->session()->get('kiosk_dtr');

        if (! $grant || (int) $grant['teacher_id'] !== $teacherId || $grant['until'] < time()) {
            return response()->json(['error' => 'Scan your ID first to view your own DTR.']);
        }

        $teacher = DB::table('teachers')->where('id', $teacherId)->first(['fullname']);

        if (! $teacher) {
            return response()->json(['error' => 'Personnel not found.']);
        }

        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime($today.' -'.(date('N', strtotime($today)) - 1).' days'));
        $weekEnd = date('Y-m-d', strtotime($weekStart.' +6 days'));
        $fmt = fn ($t) => $t ? date('h:i A', strtotime($t)) : null;

        $records = DB::table('attendance')
            ->where('teacher_id', $teacherId)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => date('M d, Y', strtotime($r->date)),
                'am_arrival' => $fmt($r->am_arrival),
                'am_departure' => $fmt($r->am_departure),
                'pm_arrival' => $fmt($r->pm_arrival),
                'pm_departure' => $fmt($r->pm_departure),
                'status' => $r->attendance_status,
            ]);

        return response()->json([
            'fullname' => $teacher->fullname,
            'records' => $records,
            'week_start' => date('M d, Y', strtotime($weekStart)),
            'week_end' => date('M d, Y', strtotime($weekEnd)),
        ]);
    }
}
