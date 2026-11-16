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

    /**
     * Audit row for a scan the kiosk refused. Was dtrLogRejectedScan(). An
     * unknown barcode has no teacher: $barcode keeps what was scanned.
     */
    public static function logRejectedScan(?int $teacherId, string $attemptedAt, string $attendanceDate, string $reason, ?string $detail, ?string $existingOut, ?string $barcode = null): void
    {
        DB::table('attendance_rejected_scans')->insert([
            'teacher_id' => $teacherId,
            'barcode' => $barcode === null ? null : mb_substr($barcode, 0, 100),
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

    /**
     * preload() for many people at once: five queries in all instead of
     * five per person (the reports, Pending Review and the Monthly Summary
     * loop over every active person). Each value has exactly preload()'s
     * shape; leaves are limited to those touching the range, which is all
     * the day rules look at.
     *
     * @param  list<int>  $teacherIds
     * @return array<int, array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>, 3: array<string, true>, 4: array<string, array<string, mixed>>}>
     */
    public static function preloadMany(array $teacherIds, string $startDate, string $endDate): array
    {
        $teacherIds = array_values(array_unique(array_map('intval', $teacherIds)));
        $data = array_fill_keys($teacherIds, [[], [], [], [], []]);

        if ($teacherIds === []) {
            return $data;
        }

        DB::table('teacher_leaves')
            ->select('teacher_id', 'leave_from', 'leave_until', 'leave_type', 'status')
            ->whereIn('teacher_id', $teacherIds)
            ->where('status', 'Approved')
            ->where('leave_from', '<=', $endDate)
            ->where('leave_until', '>=', $startDate)
            ->get()
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id][0][] = ['leave_from' => $row->leave_from, 'leave_until' => $row->leave_until, 'leave_type' => $row->leave_type, 'status' => $row->status];
            });

        DB::table('attendance_adjustments')
            ->select('teacher_id', 'adjustment_date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure', 'adjustment_type')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('adjustment_date', [$startDate, $endDate])
            ->where('is_deleted', 0)
            ->get()
            ->each(function ($row) use (&$data) {
                $row = (array) $row;
                $teacherId = (int) array_shift($row);
                $data[$teacherId][1][$row['adjustment_date']] = $row;
            });

        DB::table('attendance')
            ->select('teacher_id', 'date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->each(function ($row) use (&$data) {
                $row = (array) $row;
                $teacherId = (int) array_shift($row);
                $data[$teacherId][2][$row['date']] = $row;
            });

        DB::table('confirmed_absences')
            ->select('teacher_id', 'absence_date')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('absence_date', [$startDate, $endDate])
            ->get()
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id][3][$row->absence_date] = true;
            });

        DB::table('teacher_schedules')
            ->select('teacher_id', 'day', 'time_in', 'time_out')
            ->whereIn('teacher_id', $teacherIds)
            ->orderBy('id')
            ->get()
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id][4][strtolower($row->day)] = ['day' => $row->day, 'time_in' => $row->time_in, 'time_out' => $row->time_out];
            });

        return $data;
    }

    /**
     * The approved leave covering $date for one person, or null.
     *
     * @return ?array{id: int, leave_type: string, leave_from: string, leave_until: string, reason: ?string}
     */
    public static function leaveOn(int $teacherId, string $date): ?array
    {
        $leave = DB::table('teacher_leaves')
            ->select('id', 'leave_type', 'leave_from', 'leave_until', 'reason')
            ->where('teacher_id', $teacherId)
            ->where('status', 'Approved')
            ->where('leave_from', '<=', $date)
            ->where('leave_until', '>=', $date)
            ->orderByDesc('id')
            ->first();

        return $leave ? ['id' => (int) $leave->id] + (array) $leave : null;
    }

    /** One person's weekly schedule, keyed by lower-case weekday name. */
    public static function schedules(int $teacherId): array
    {
        $schedules = [];

        DB::table('teacher_schedules')
            ->select('day', 'time_in', 'time_out')
            ->where('teacher_id', $teacherId)
            ->orderBy('id')
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

    /**
     * remarksByDate() for many people in one query.
     *
     * @param  list<int>  $teacherIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    public static function remarksByDateMany(array $teacherIds, string $startDate, string $endDate): array
    {
        $remarks = [];

        if ($teacherIds === []) {
            return $remarks;
        }

        DB::table('dtr_remarks')
            ->select('teacher_id', 'date', 'remark_type', 'duration', 'half_day_session', 'included_in_total_hours')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->each(function ($row) use (&$remarks) {
                $row = (array) $row;
                $teacherId = (int) array_shift($row);
                $remarks[$teacherId][$row['date']] = $row;
            });

        return $remarks;
    }
}
