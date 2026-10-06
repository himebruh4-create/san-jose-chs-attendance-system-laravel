<?php

namespace App\Http\Controllers\Data;

use App\Domain\Attendance\AttendanceData;
use App\Domain\Attendance\BreakConfig;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data the DTR screens read and the DTR remarks they save (Super Admin and
 * Admin). Ported from db/db-check-recording-status.php,
 * dtr/get-school-events.php, get-teacher-schedule.php, get-teacher-leaves.php,
 * get-teacher-adjustments.php, get-confirmed-absences.php,
 * get-dtr-remarks.php and save-dtr-remarks.php.
 */
class DtrDataController extends Controller
{
    public function recordingStatus()
    {
        $row = DB::table('system_settings')->where('id', 1)
            ->first(['attendance_recording_enabled', 'disabled_reason', 'lunch_out', 'lunch_in']);

        return $this->ok([
            'enabled' => (int) ($row->attendance_recording_enabled ?? 1),
            'reason' => $row->disabled_reason ?? '',
            'lunch_out' => $row->lunch_out ?? '12:15:00',
            'lunch_in' => $row->lunch_in ?? '13:00:00',
            'schedule_breaks' => BreakConfig::load()['entries'],
        ]);
    }

    public function schoolEvents()
    {
        return $this->ok(['events' => AttendanceData::activeEvents()]);
    }

    public function teacherSchedule(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');

        if ($teacherId <= 0) {
            return $this->fail('Teacher ID is required.');
        }

        $schedule = [];
        foreach (AttendanceData::schedules($teacherId) as $day => $row) {
            $schedule[$day] = ['time_in' => $row['time_in'], 'time_out' => $row['time_out']];
        }

        return $this->ok(['schedule' => $schedule]);
    }

    public function teacherLeaves(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');

        if ($teacherId <= 0) {
            return $this->fail('Teacher ID is required.');
        }

        $leaves = DB::table('teacher_leaves')
            ->select('id', 'teacher_id', 'leave_type', 'leave_from', 'leave_until', 'reason', 'status')
            ->where('teacher_id', $teacherId)
            ->where('status', 'Approved')
            ->orderBy('leave_from')
            ->get();

        return $this->ok(['leaves' => $leaves]);
    }

    public function teacherAdjustments(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');
        $start = $this->date($request->query('start'));
        $end = $this->date($request->query('end'));

        if ($teacherId <= 0) {
            return $this->fail('Teacher ID is required.');
        }

        if (! $start || ! $end) {
            return $this->fail('Start and end date are required.');
        }

        $adjustments = DB::table('attendance_adjustments')
            ->select('id', 'teacher_id', 'adjustment_date', 'am_arrival', 'am_departure', 'pm_arrival',
                'pm_departure', 'reason', 'remarks', 'adjustment_type', 'status')
            ->where('teacher_id', $teacherId)
            ->whereBetween('adjustment_date', [$start, $end])
            ->where('is_deleted', 0)
            ->orderBy('adjustment_date')
            ->get();

        return $this->ok(['adjustments' => $adjustments]);
    }

    public function confirmedAbsences(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');
        $start = $this->date($request->query('start'));
        $end = $this->date($request->query('end'));

        if ($teacherId <= 0 || ! $start || ! $end) {
            return $this->fail('Teacher ID, start, and end date are required.');
        }

        $absences = DB::table('confirmed_absences')
            ->where('teacher_id', $teacherId)
            ->whereBetween('absence_date', [$start, $end])
            ->pluck('absence_date');

        return $this->ok(['absences' => $absences]);
    }

    public function dtrRemarks(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');
        $start = $this->date($request->query('start'));
        $end = $this->date($request->query('end'));

        if ($teacherId <= 0 || ! $start || ! $end) {
            return $this->fail('Teacher ID, start, and end date are required.');
        }

        $remarks = DB::table('dtr_remarks')
            ->select('date', 'date_from', 'date_to', 'actual_return_date', 'status', 'remark_type', 'remark_text',
                'duration', 'half_day_session', 'included_in_total_hours')
            ->where('teacher_id', $teacherId)
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get();

        return $this->ok(['remarks' => $remarks]);
    }

    /**
     * Replaces one person's remarks for one month (was save-dtr-remarks.php).
     */
    public function saveDtrRemarks(Request $request)
    {
        $teacherId = (int) $request->input('teacher_id');
        $monthStart = $this->date($request->input('month_start'));
        $monthEnd = $this->date($request->input('month_end'));
        $remarks = json_decode((string) $request->input('remarks', '[]'), true);

        if ($teacherId <= 0 || ! $monthStart || ! $monthEnd) {
            return $this->fail('Missing required information.');
        }

        if (! DB::table('teachers')->where('id', $teacherId)->exists()) {
            return $this->fail('Personnel not found.');
        }

        if (! is_array($remarks)) {
            $remarks = [];
        }

        try {
            DB::transaction(function () use ($teacherId, $monthStart, $monthEnd, $remarks) {

                DB::table('dtr_remarks')
                    ->where('teacher_id', $teacherId)
                    ->where(function ($q) use ($monthStart, $monthEnd) {
                        $q->whereBetween('date', [$monthStart, $monthEnd])
                            ->orWhereBetween('date_from', [$monthStart, $monthEnd])
                            ->orWhereBetween('date_to', [$monthStart, $monthEnd])
                            ->orWhere(fn ($q2) => $q2->where('date_from', '<=', $monthStart)->where('date_to', '>=', $monthEnd));
                    })
                    ->delete();

                foreach ($remarks as $remark) {
                    if (! is_array($remark)) {
                        continue;
                    }

                    $type = trim((string) ($remark['remark_type'] ?? ''));
                    $text = trim((string) ($remark['remark_text'] ?? ''));

                    if ($type === '') {
                        continue;
                    }

                    // Duration / session / included only apply to Official
                    // Business; every other type is Whole Day, not included.
                    $duration = 'Whole Day';
                    $halfDaySession = null;
                    $included = 0;

                    if ($type === 'Official Business') {
                        $duration = trim((string) ($remark['duration'] ?? 'Whole Day'));

                        if (! in_array($duration, ['Whole Day', 'Half Day'], true)) {
                            throw new \DomainException('Invalid duration for Official Business.');
                        }

                        $includedRaw = (string) ($remark['included_in_total_hours'] ?? '');

                        if (! in_array($includedRaw, ['0', '1'], true)) {
                            throw new \DomainException('Please choose whether Official Business is included in total hours.');
                        }

                        $included = (int) $includedRaw;

                        if ($duration === 'Half Day') {
                            $halfDaySession = trim((string) ($remark['half_day_session'] ?? ''));

                            if (! in_array($halfDaySession, ['AM', 'PM'], true)) {
                                throw new \DomainException('Please choose the AM or PM session for a Half Day Official Business.');
                            }
                        }
                    }

                    if ($type === 'On Leave') {
                        $dateFrom = $this->date($remark['date_from'] ?? null);
                        $dateTo = $this->date($remark['date_to'] ?? null);

                        if (! $dateFrom || ! $dateTo) {
                            continue;
                        }

                        if ($dateFrom > $dateTo) {
                            throw new \DomainException('On Leave start date cannot be later than end date.');
                        }

                        // Only saved when the leave overlaps the selected month.
                        if ($dateTo < $monthStart || $dateFrom > $monthEnd) {
                            continue;
                        }

                        $status = trim((string) ($remark['status'] ?? 'Active'));
                        if (! in_array($status, ['Active', 'Ended', 'Ended Early', 'Cancelled'], true)) {
                            $status = 'Active';
                        }

                        DB::table('dtr_remarks')->insert([
                            'teacher_id' => $teacherId,
                            'date_from' => $dateFrom,
                            'date_to' => $dateTo,
                            'actual_return_date' => $this->date($remark['actual_return_date'] ?? null),
                            'status' => $status,
                            'date' => $dateFrom,
                            'remark_type' => mb_substr($type, 0, 50),
                            'remark_text' => mb_substr($text, 0, 255),
                            'duration' => $duration,
                            'half_day_session' => $halfDaySession,
                            'included_in_total_hours' => $included,
                        ]);

                        continue;
                    }

                    $date = $this->date($remark['date'] ?? null);

                    // Only dates inside the selected month.
                    if (! $date || $date < $monthStart || $date > $monthEnd) {
                        continue;
                    }

                    DB::table('dtr_remarks')->insert([
                        'teacher_id' => $teacherId,
                        'date_from' => $date,
                        'date_to' => $date,
                        'actual_return_date' => null,
                        'status' => 'Active',
                        'date' => $date,
                        'remark_type' => mb_substr($type, 0, 50),
                        'remark_text' => mb_substr($text, 0, 255),
                        'duration' => $duration,
                        'half_day_session' => $halfDaySession,
                        'included_in_total_hours' => $included,
                    ]);
                }
            });
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Unable to save the DTR remarks. Please try again.');
        }

        Audit::log('dtr_remarks.saved', 'teacher', $teacherId, ['month_start' => $monthStart, 'count' => count($remarks)]);

        return $this->ok(['message' => 'DTR remarks saved successfully.']);
    }
}
