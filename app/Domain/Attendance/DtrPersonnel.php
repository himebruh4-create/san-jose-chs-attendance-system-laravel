<?php

namespace App\Domain\Attendance;

use App\Models\Account;
use App\Support\Printout;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The people and data behind the Attendance Report's DTRs
 * (public/js/dtr-view.js): one person's DTR payload for a month, and every
 * matching person's DTR data for Generate All DTR. Each is read in a fixed
 * number of queries, never one (or seven requests) per person, and only
 * when it is needed: the page itself carries the current page's people.
 */
class DtrPersonnel
{
    private const COLUMNS = ['id', 'id_number', 'fullname', 'department', 'academic_status', 'employment_type'];

    /** Active personnel of a type tab ('' = all) matching the search, by name. */
    public static function query(string $search, string $personnelType): Builder
    {
        return DB::table('teachers')
            ->where('is_deleted', 0)
            ->when($personnelType !== '', fn ($q) => $q->where('academic_status', $personnelType))
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('fullname', 'like', "%{$search}%")->orWhere('id_number', 'like', "%{$search}%")))
            ->orderBy('fullname');
    }

    /**
     * The payload Generate DTR reads (data-teacher): the person, their raw
     * scans of the month, the month itself and the printout's name.
     *
     * @param  iterable<object>  $teachers  rows with self::COLUMNS
     * @return list<array<string, mixed>>
     */
    public static function payloads(iterable $teachers, string $monthStart, Account $account): array
    {
        $teachers = collect($teachers);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $records = self::recordsMany($teachers->pluck('id')->map(fn ($id) => (int) $id)->all(), $monthStart, $monthEnd);
        $printMonth = Printout::month($monthStart);

        return $teachers->map(fn ($t) => [
            'id' => (int) $t->id,
            'id_number' => $t->id_number,
            'fullname' => $t->fullname,
            'department' => $t->department,
            'academic_status' => $t->academic_status,
            'employment_type' => $t->employment_type,
            'schedule_session' => null,
            'records' => $records[(int) $t->id] ?? [],
            'monthStart' => $monthStart,
            // "DTR - Ana Cruz - 2026-10 - Administrative Officer" (saved PDF name)
            'print' => Printout::job($account, 'DTR', $printMonth, $t->fullname, (int) $t->id),
        ])->values()->all();
    }

    /** @return list<string> */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * Everything printDTRWithRemarks() needs for each person of $teacherIds
     * in one month, plus the shared events and break settings: the same
     * shapes the per-person /data/teacher-* endpoints return.
     *
     * @param  list<int>  $teacherIds
     * @return array<int, array{leaves: list<object>, adjustments: list<object>, absences: list<string>, schedule: array<string, array{time_in: string, time_out: string}>, remarks: list<object>}>
     */
    public static function monthData(array $teacherIds, string $monthStart, string $monthEnd): array
    {
        $data = array_fill_keys($teacherIds, ['leaves' => [], 'adjustments' => [], 'absences' => [], 'schedule' => [], 'remarks' => []]);

        if ($teacherIds === []) {
            return $data;
        }

        DB::table('teacher_leaves')
            ->select('id', 'teacher_id', 'leave_type', 'leave_from', 'leave_until', 'reason', 'status')
            ->whereIn('teacher_id', $teacherIds)
            ->where('status', 'Approved')
            ->where('leave_from', '<=', $monthEnd)
            ->where('leave_until', '>=', $monthStart)
            ->orderBy('leave_from')
            ->get()
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id]['leaves'][] = $row;
            });

        DB::table('attendance_adjustments')
            ->select('id', 'teacher_id', 'adjustment_date', 'am_arrival', 'am_departure', 'pm_arrival',
                'pm_departure', 'reason', 'remarks', 'adjustment_type', 'status')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('adjustment_date', [$monthStart, $monthEnd])
            ->where('is_deleted', 0)
            ->orderBy('adjustment_date')
            ->get()
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id]['adjustments'][] = $row;
            });

        DB::table('confirmed_absences')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('absence_date', [$monthStart, $monthEnd])
            ->get(['teacher_id', 'absence_date'])
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id]['absences'][] = $row->absence_date;
            });

        DB::table('teacher_schedules')
            ->whereIn('teacher_id', $teacherIds)
            ->orderBy('id')
            ->get(['teacher_id', 'day', 'time_in', 'time_out'])
            ->each(function ($row) use (&$data) {
                $data[(int) $row->teacher_id]['schedule'][strtolower($row->day)] = ['time_in' => $row->time_in, 'time_out' => $row->time_out];
            });

        DB::table('dtr_remarks')
            ->select('teacher_id', 'date', 'date_from', 'date_to', 'actual_return_date', 'status', 'remark_type', 'remark_text',
                'duration', 'half_day_session', 'included_in_total_hours')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->orderBy('date')
            ->get()
            ->each(function ($row) use (&$data) {
                $teacherId = (int) $row->teacher_id;
                unset($row->teacher_id);
                $data[$teacherId]['remarks'][] = $row;
            });

        return $data;
    }

    /**
     * AttendanceData::teacherRecords() for many people in one query.
     *
     * @param  list<int>  $teacherIds
     * @return array<int, list<array<string, ?string>>>
     */
    private static function recordsMany(array $teacherIds, string $startDate, string $endDate): array
    {
        $records = [];

        if ($teacherIds === []) {
            return $records;
        }

        DB::table('attendance')
            ->select('teacher_id', 'date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure')
            ->whereIn('teacher_id', $teacherIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get()
            ->each(function ($row) use (&$records) {
                $row = (array) $row;
                $teacherId = (int) array_shift($row);
                $records[$teacherId][] = $row;
            });

        return $records;
    }
}
