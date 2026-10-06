<?php

namespace App\Domain\Attendance;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records one kiosk scan. Ported from save-attendance.php: routing is
 * schedule-aware (dtrRouteScan): the first valid scan of a shift is the IN,
 * the final valid scan is the OUT, and once an OUT exists every further
 * scan is rejected and logged. Nothing is ever invented from the schedule.
 *
 * The response is the JSON the kiosk page shows. New fields: `status`
 * (Late / On-Time on an arrival) and `kind`, so the page no longer has to
 * search the message text.
 */
class Scanner
{
    public function scan(string $barcode, ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');

        $settings = DB::table('system_settings')->where('id', 1)->first(['attendance_recording_enabled', 'disabled_reason']);

        if ($settings && ! $settings->attendance_recording_enabled) {
            return [
                'success' => false,
                'outcome' => 'recording_disabled',
                'title' => 'Recording Disabled',
                'message' => $settings->disabled_reason ?: 'Attendance recording is currently disabled.',
            ];
        }

        if ($barcode === '') {
            return ['success' => false, 'outcome' => 'invalid', 'title' => 'Error', 'message' => 'Barcode is required.'];
        }

        $t = DB::table('teachers')
            ->where('barcode', $barcode)
            ->where('is_deleted', 0)
            ->first(['id', 'fullname', 'photo']);

        if (! $t) {
            return ['success' => false, 'outcome' => 'not_found', 'title' => 'Not Found', 'message' => 'Invalid barcode.'];
        }

        $teacherId = (int) $t->id;
        $fullname = $t->fullname;
        $photo = ! empty($t->photo) ? basename($t->photo) : null;

        $date = substr($now, 0, 10);
        $time = substr($now, 11, 8);
        $prevDate = date('Y-m-d', strtotime($date.' -1 day'));
        $clock = date('h:i A', strtotime($now));

        $todaySchedule = AttendanceData::scheduleFor($teacherId, $date);
        $prevSchedule = AttendanceData::scheduleFor($teacherId, $prevDate);
        $todayRow = $this->row($teacherId, $date);
        $prevRow = ($prevSchedule && dtrScheduleShape($prevSchedule) === 'overnight')
            ? $this->row($teacherId, $prevDate)
            : null;

        $route = dtrRouteScan([
            'date' => $date,
            'time' => $time,
            'schedule' => $todaySchedule,
            'prevSchedule' => $prevSchedule,
            'row' => $todayRow,
            'prevRow' => $prevRow,
            'break' => dtrBreakForSchedule(BreakConfig::load(), $todaySchedule),
        ]);

        $base = ['teacher_id' => $teacherId, 'fullname' => $fullname, 'photo' => $photo, 'time' => $clock];

        // ---- Rejected: not stored, the kiosk explains why, logged for review.
        if ($route['action'] === 'reject') {

            $this->logRejected($teacherId, $now, $route['date'], $route['reason'], $route['detail'], $route['existing_out']);

            if ($route['reason'] === 'DUPLICATE_SCAN') {
                return $base + [
                    'success' => false,
                    'outcome' => 'duplicate',
                    'title' => 'Duplicate Scan',
                    'message' => 'Your scan was already recorded a moment ago. Please scan only once.',
                ];
            }

            $outText = $route['existing_out'] !== ''
                ? ' (OUT recorded at '.date('h:i A', strtotime($route['existing_out'])).')'
                : '';

            return $base + [
                'success' => false,
                'outcome' => 'complete',
                'title' => 'Attendance Already Complete',
                'message' => "{$fullname}, your attendance for this shift is already complete{$outText}. This extra scan was not saved. Please see the Super Admin if a correction is needed.",
            ];
        }

        // ---- Store the real scan.
        $column = $route['column'];          // am_arrival | am_departure | pm_arrival | pm_departure
        $targetDate = $route['date'];
        $isOut = $route['kind'] === 'OUT';
        $duplicateRace = false;

        try {
            if ($route['insert']) {
                DB::table('attendance')->insert([
                    'teacher_id' => $teacherId,
                    'date' => $targetDate,
                    $column => $time,
                    'attendance_status' => $column === 'am_arrival' ? 'AM Active' : 'PM Active',
                ]);
            } else {
                $status = $isOut ? 'Complete PM'
                    : ($column === 'am_arrival' ? 'AM Active' : ($column === 'am_departure' ? 'Complete AM' : 'PM Active'));

                // Only fills an EMPTY column: a stored scan is never overwritten.
                $affected = DB::table('attendance')
                    ->where('teacher_id', $teacherId)
                    ->where('date', $targetDate)
                    ->whereNull($column)
                    ->update([$column => $time, 'attendance_status' => $status, 'updated_at' => now()]);

                $duplicateRace = $affected < 1;
            }
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                // Two scans raced for the same (teacher, date) row.
                $duplicateRace = true;
            } else {
                Log::error('Kiosk attendance write failed: '.$e->getMessage());

                return $base + [
                    'success' => false,
                    'outcome' => 'error',
                    'title' => 'Error',
                    'message' => 'Unable to save your attendance right now. Please try again or notify the Super Admin.',
                ];
            }
        }

        if ($duplicateRace) {
            $this->logRejected($teacherId, $now, $targetDate, 'DUPLICATE_SCAN', 'Simultaneous scan', '');

            return $base + [
                'success' => false,
                'outcome' => 'duplicate',
                'title' => 'Duplicate Scan',
                'message' => 'Your scan was already recorded a moment ago. Please scan only once.',
            ];
        }

        // ---- Kiosk message.
        $status = null;

        if ($route['kind'] === 'BREAK_OUT') {
            $message = "{$fullname} left for lunch at {$clock}";
        } elseif ($route['kind'] === 'BREAK_IN') {
            $message = "{$fullname} is back from lunch at {$clock}";
        } elseif (! $isOut) {
            $isLate = ($todaySchedule && ! empty($todaySchedule['time_in']))
                ? (dtrSecs($time) > dtrSecs($todaySchedule['time_in']))
                : false;
            $status = $isLate ? 'Late' : 'On-Time';
            $message = "{$fullname} marked {$status} at {$clock}";
        } else {
            $schedForShift = ! empty($route['overnight']) ? $prevSchedule : $todaySchedule;
            $calc = dtrDayCalc($schedForShift, $this->row($teacherId, $targetDate), null, []);

            $message = "{$fullname} left at {$clock}";

            if (! empty($route['overnight'])) {
                $message .= ' (overnight shift)';
            }

            if ($calc['overtime']) {
                $message .= '. Overtime recorded — it needs Super Admin approval; hours are credited up to your scheduled end.';
            } elseif ($calc['early_departure']) {
                $message .= '. Early departure recorded for review.';
            }
        }

        return $base + [
            'success' => true,
            'outcome' => 'accepted',
            'kind' => $route['kind'],
            'status' => $status,
            'date' => date('M d, Y', strtotime($now)),
            'attendance_date' => $targetDate,
            'title' => $route['title'],
            'message' => $message,
        ];
    }

    private function row(int $teacherId, string $date): ?array
    {
        $row = DB::table('attendance')
            ->where('teacher_id', $teacherId)
            ->where('date', $date)
            ->first(['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure']);

        return $row ? (array) $row : null;
    }

    private function logRejected(int $teacherId, string $attemptedAt, string $attendanceDate, string $reason, ?string $detail, ?string $existingOut): void
    {
        try {
            AttendanceData::logRejectedScan($teacherId, $attemptedAt, $attendanceDate, $reason, $detail, $existingOut);
        } catch (\Throwable $e) {
            // The kiosk must still answer even if the audit table is unavailable.
            Log::warning('Could not log a rejected scan: '.$e->getMessage());
        }
    }
}
