<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Database reads and writes the attendance rules need. Every method returns
 * plain arrays in the shapes the native-PHP functions returned, so the rule
 * functions in rules.php work on them unchanged.
 */
class AttendanceData
{
    /**
     * Active School Schedule Changes, each with `worker_ids`: the active
     * personnel scheduled to work through it. Was dtrLoadActiveEvents().
     */
    public static function activeEvents(): array
    {
        $events = DB::table('school_events')
            ->select('id', 'event_name', 'event_type', 'date_from', 'date_to', 'status', 'duration',
                'included_in_total_hours', 'start_time', 'end_time', 'remark')
            ->where('status', 'Active')
            ->orderBy('date_from')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row + ['worker_ids' => []])
            ->all();

        if (! $events) {
            return $events;
        }

        $byEvent = [];

        DB::table('school_event_personnel as ep')
            ->join('teachers as t', function ($join) {
                $join->on('t.id', '=', 'ep.teacher_id')->where('t.is_deleted', 0);
            })
            ->whereIn('ep.school_event_id', array_column($events, 'id'))
            ->get(['ep.school_event_id', 'ep.teacher_id'])
            ->each(function ($row) use (&$byEvent) {
                $byEvent[(int) $row->school_event_id][] = (int) $row->teacher_id;
            });

        foreach ($events as &$event) {
            $event['worker_ids'] = $byEvent[(int) $event['id']] ?? [];
        }

        return $events;
    }

    /** Raw attendance rows of one person in a date range. Was getTeacherRecords(). */
    public static function teacherRecords(int $teacherId, string $startDate, string $endDate): array
    {
        return DB::table('attendance')
            ->select('date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure')
            ->where('teacher_id', $teacherId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** Audit row for a scan the kiosk refused. Was dtrLogRejectedScan(). */
    public static function logRejectedScan(int $teacherId, string $attemptedAt, string $attendanceDate, string $reason, ?string $detail, ?string $existingOut): void
    {
        DB::table('attendance_rejected_scans')->insert([
            'teacher_id' => $teacherId,
            'attempted_at' => $attemptedAt,
            'attendance_date' => $attendanceDate,
            'reason' => $reason,
            'detail' => $detail,
            'existing_out_time' => ($existingOut === null || $existingOut === '') ? null : $existingOut,
        ]);
    }

    /**
     * Everything the day rules need for one person and one date range:
     * [$leaves, $adjustments (by date), $attendance (by date),
     *  $confirmedAbsences (date => true), $schedules (weekday => row)].
     * Was dtrPreloadTeacherData() / the loaders in dtrPendingReviewForTeacher().
     */
    public static function preload(int $teacherId, string $startDate, string $endDate): array
    {
        $leaves = DB::table('teacher_leaves')
            ->select('leave_from', 'leave_until', 'leave_type', 'status')
            ->where('teacher_id', $teacherId)
            ->where('status', 'Approved')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $adjustments = [];
        DB::table('attendance_adjustments')
            ->select('adjustment_date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure', 'adjustment_type')
            ->where('teacher_id', $teacherId)
            ->whereBetween('adjustment_date', [$startDate, $endDate])
            ->where('is_deleted', 0)
            ->get()
            ->each(function ($row) use (&$adjustments) {
                $adjustments[$row->adjustment_date] = (array) $row;
            });

        $attendance = [];
        DB::table('attendance')
            ->select('date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure')
            ->where('teacher_id', $teacherId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->each(function ($row) use (&$attendance) {
                $attendance[$row->date] = (array) $row;
            });

        $confirmedAbsences = [];
        DB::table('confirmed_absences')
            ->where('teacher_id', $teacherId)
            ->whereBetween('absence_date', [$startDate, $endDate])
            ->pluck('absence_date')
            ->each(function ($date) use (&$confirmedAbsences) {
                $confirmedAbsences[$date] = true;
            });

        return [$leaves, $adjustments, $attendance, $confirmedAbsences, self::schedules($teacherId)];
    }

    /** One person's weekly schedule, keyed by lower-case weekday name. */
    public static function schedules(int $teacherId): array
    {
        $schedules = [];

        DB::table('teacher_schedules')
            ->select('day', 'time_in', 'time_out')
            ->where('teacher_id', $teacherId)
            ->get()
            ->each(function ($row) use (&$schedules) {
                $schedules[strtolower($row->day)] = (array) $row;
            });

        return $schedules;
    }

    /** One person's schedule row for the weekday of $date, or null. */
    public static function scheduleFor(int $teacherId, string $date): ?array
    {
        $row = DB::table('teacher_schedules')
            ->select('time_in', 'time_out')
            ->where('teacher_id', $teacherId)
            ->whereRaw('LOWER(day) = ?', [strtolower(date('l', strtotime($date)))])
            ->first();

        return $row ? (array) $row : null;
    }

    /** This person's DTR remarks in a date range, keyed by date. */
    public static function remarksByDate(int $teacherId, string $startDate, string $endDate): array
    {
        $remarks = [];

        DB::table('dtr_remarks')
            ->select('date', 'remark_type', 'duration', 'half_day_session', 'included_in_total_hours')
            ->where('teacher_id', $teacherId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->each(function ($row) use (&$remarks) {
                $remarks[$row->date] = (array) $row;
            });

        return $remarks;
    }
}
