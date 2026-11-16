<?php

namespace App\Http\Controllers\Data;

use App\Domain\LeaveTypes;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings actions (Super Admin). Ported from superadmin/save-lunch-break.php,
 * toggle-attendance-recording.php, get-recycle-bin.php and
 * recycle-bin-action.php. The audit log view is new.
 */
class SettingsDataController extends Controller
{
    public function saveLunchBreak(Request $request)
    {
        $lunchOut = trim((string) $request->input('lunch_out'));
        $lunchIn = trim((string) $request->input('lunch_in'));

        if ($lunchOut === '' || $lunchIn === '') {
            return $this->fail('Both lunch times are required.');
        }

        if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $lunchOut) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $lunchIn)) {
            return $this->fail('Please enter valid lunch times.');
        }

        $update = ['lunch_out' => $lunchOut, 'lunch_in' => $lunchIn];

        // Schedule-specific paid breaks (optional). Each belongs to an
        // assigned schedule (time in / time out), never to a person.
        if ($request->has('schedule_breaks')) {
            $decoded = json_decode((string) $request->input('schedule_breaks'), true);

            if (! is_array($decoded)) {
                return $this->fail('Invalid schedule break data.');
            }

            $clean = [];
            $seen = [];

            foreach ($decoded as $e) {
                $times = [];

                foreach (['time_in', 'time_out', 'break_start', 'break_end'] as $k) {
                    $v = trim((string) (is_array($e) ? ($e[$k] ?? '') : ''));
                    if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) {
                        return $this->fail('Every schedule break needs a valid time in, time out, break start and break end.');
                    }
                    $times[$k] = strlen($v) === 5 ? $v.':00' : $v;
                }

                if ($times['time_in'] === $times['time_out']) {
                    return $this->fail('Time in and time out of a schedule cannot be the same.');
                }

                $key = $times['time_in'].'|'.$times['time_out'];

                if (isset($seen[$key])) {
                    return $this->fail('Each schedule can only have one break.');
                }

                $seen[$key] = true;
                $clean[] = $times;
            }

            $update['schedule_breaks'] = json_encode($clean);
        }

        DB::table('system_settings')->where('id', 1)->update($update);
        Audit::log('settings.lunch_break', 'system_settings', 1, $update);

        return $this->ok(['message' => 'Lunch break updated.']);
    }

    public function toggleRecording(Request $request)
    {
        $enabled = $request->has('enabled') ? ((int) $request->input('enabled') ? 1 : 0) : 1;
        $reason = mb_substr(trim((string) $request->input('reason')), 0, 255);

        DB::table('system_settings')->where('id', 1)->update([
            'attendance_recording_enabled' => $enabled,
            'disabled_reason' => $reason,
        ]);

        Audit::log($enabled ? 'settings.recording_enabled' : 'settings.recording_disabled', 'system_settings', 1, ['reason' => $reason]);

        return $this->ok([
            'message' => $enabled ? 'Attendance recording enabled.' : 'Attendance recording disabled.',
            'enabled' => $enabled,
        ]);
    }

    // ------------------------------------------------------------ recycle bin

    /** personnel_on_leave: soft-deleted personnel who were on approved leave the day they were archived. */
    private const ON_LEAVE_SQL = "EXISTS (SELECT 1 FROM teacher_leaves tl WHERE tl.teacher_id = teachers.id
        AND tl.status = 'Approved' AND DATE(teachers.deleted_at) BETWEEN tl.leave_from AND tl.leave_until)";

    /** Rows per page of each Recycle Bin category. */
    public const RECYCLE_BIN_PER_PAGE = 20;

    /** Recycle Bin category => department_options.option_type. */
    private const OPTION_TYPES = ['subject' => 'Subject', 'position' => 'Position', 'leave_type' => LeaveTypes::OPTION_TYPE];

    /** A leave type no leave record uses (only those can be permanently deleted). */
    private const LEAVE_TYPE_UNUSED_SQL = 'NOT EXISTS (SELECT 1 FROM teacher_leaves tl WHERE tl.leave_type = department_options.option_name)';

    /** One ?page= of a category, newest first, with its total (for Delete All). */
    public function recycleBin(Request $request)
    {
        $fmt = fn ($d) => $d ? date('M j, Y', strtotime($d)) : '—';
        $type = $request->query('type');

        [$query, $columns, $toRow] = match ($type) {
            'teacher', 'personnel_on_leave' => [
                DB::table('teachers')
                    ->where('is_deleted', 1)
                    ->when($type === 'personnel_on_leave', fn ($q) => $q->whereRaw(self::ON_LEAVE_SQL))
                    ->orderByDesc('deleted_at')
                    ->orderByDesc('id'),
                ['id', 'fullname', 'id_number', 'department', 'deleted_at', 'deleted_by'],
                fn ($r) => [
                    'id' => (int) $r->id,
                    'title' => $r->fullname,
                    'subtitle' => $r->id_number.' · '.$r->department,
                    'deleted_at' => $fmt($r->deleted_at),
                    'deleted_by' => $r->deleted_by ?: '—',
                ],
            ],

            'attendance_adjustment' => [
                DB::table('attendance_adjustments as aa')
                    ->join('teachers as t', 't.id', '=', 'aa.teacher_id')
                    ->where('aa.is_deleted', 1)
                    ->orderByDesc('aa.deleted_at')
                    ->orderByDesc('aa.id'),
                ['aa.id', 'aa.adjustment_date', 'aa.adjustment_type', 'aa.deleted_at', 'aa.deleted_by', 't.fullname', 't.id_number'],
                fn ($r) => [
                    'id' => (int) $r->id,
                    'title' => $r->fullname.' — '.date('M j, Y', strtotime($r->adjustment_date)),
                    'subtitle' => $r->id_number.' · '.$r->adjustment_type,
                    'deleted_at' => $fmt($r->deleted_at),
                    'deleted_by' => $r->deleted_by ?: '—',
                ],
            ],

            'school_event' => [
                DB::table('school_events')->where('status', 'Inactive')->orderByDesc('id'),
                ['id', 'event_name', 'event_type', 'date_from', 'date_to'],
                fn ($r) => [
                    'id' => (int) $r->id,
                    'title' => $r->event_name,
                    'subtitle' => $r->event_type.' · '.date('M j', strtotime($r->date_from)).' - '.date('M j, Y', strtotime($r->date_to)),
                    'deleted_at' => '—',
                    'deleted_by' => '—',
                ],
            ],

            'subject', 'position', 'leave_type' => [
                DB::table('department_options')
                    ->where('option_type', self::OPTION_TYPES[$type])
                    ->where('status', 'Inactive')
                    ->orderByDesc('id'),
                ['id', 'option_name', 'option_type', 'personnel_type'],
                fn ($r) => [
                    'id' => (int) $r->id,
                    'title' => $r->option_name,
                    'subtitle' => $r->option_type.($r->personnel_type ? ' · '.$r->personnel_type : ''),
                    'deleted_at' => '—',
                    'deleted_by' => '—',
                ],
            ],

            'account' => [
                DB::table('accounts')
                    ->where('is_deleted', 1)
                    ->whereIn('role', ['admin', 'principal'])
                    ->orderByDesc('deleted_at')
                    ->orderByDesc('id'),
                ['id', 'full_name', 'email', 'role', 'deleted_at', 'deleted_by'],
                fn ($r) => [
                    'id' => (int) $r->id,
                    'title' => $r->full_name,
                    'subtitle' => $r->email.' · '.ucfirst($r->role),
                    'deleted_at' => $fmt($r->deleted_at),
                    'deleted_by' => $r->deleted_by ?: '—',
                ],
            ],

            default => [null, [], null],
        };

        if ($query === null) {
            return $this->fail('Invalid category.');
        }

        $total = (clone $query)->count();
        $pages = Pagination::page((int) $request->query('page', 1), $total, self::RECYCLE_BIN_PER_PAGE);

        return $this->ok([
            'rows' => $query->offset($pages['offset'])->limit(self::RECYCLE_BIN_PER_PAGE)->get($columns)->map($toRow)->values(),
            'total' => $total,
            'page' => $pages['page'],
            'last_page' => $pages['last_page'],
        ]);
    }

    public function recycleBinAction(Request $request)
    {
        $type = (string) $request->input('type');
        $action = (string) $request->input('action');
        $id = (int) $request->input('id');

        $validTypes = ['teacher', 'personnel_on_leave', 'attendance_adjustment', 'school_event', 'subject', 'position', 'leave_type', 'account'];
        $validActions = ['restore', 'permanent_delete', 'delete_all'];

        if (! in_array($type, $validTypes, true) || ! in_array($action, $validActions, true)) {
            return $this->fail('Invalid request.');
        }

        if ($action !== 'delete_all' && $id <= 0) {
            return $this->fail('Invalid request.');
        }

        // A leave type a leave record uses is never deleted (Delete All skips it).
        if ($type === 'leave_type' && $action === 'permanent_delete') {
            $name = DB::table('department_options')->where('id', $id)->where('option_type', LeaveTypes::OPTION_TYPE)->value('option_name');

            if ($name !== null && LeaveTypes::isUsed($name)) {
                return $this->fail(DepartmentOptionController::leaveTypeInUseMessage($name), 200, ['affected' => 0]);
            }
        }

        try {
            $affected = DB::transaction(fn () => $this->applyRecycleBinAction($type, $action, $id, $request->user()->email));
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Action failed.', 200, ['affected' => 0]);
        }

        if ($action !== 'delete_all' && $affected === 0) {
            return $this->fail('Record not found (it may have already been restored or deleted).', 200, ['affected' => 0]);
        }

        Audit::log('recycle_bin.'.$action, $type, $id ?: null, ['affected' => $affected]);

        $message = match (true) {
            $action === 'delete_all' => $affected > 0 ? "{$affected} record(s) permanently deleted." : 'No archived records to delete.',
            $action === 'restore' => 'Record restored.',
            default => 'Record permanently deleted.',
        };

        return $this->ok(['affected' => $affected, 'message' => $message]);
    }

    private function applyRecycleBinAction(string $type, string $action, int $id, string $actor): int
    {
        // Tables using is_deleted / deleted_at / deleted_by.
        $softDelete = ['teacher' => 'teachers', 'personnel_on_leave' => 'teachers', 'attendance_adjustment' => 'attendance_adjustments', 'account' => 'accounts'];
        // Tables whose Inactive status is the soft delete.
        $status = ['school_event' => 'school_events', 'subject' => 'department_options', 'position' => 'department_options', 'leave_type' => 'department_options'];

        if (isset($softDelete[$type])) {
            $query = DB::table($softDelete[$type])->where('is_deleted', 1);

            // Never touch a Super Admin account.
            if ($type === 'account') {
                $query->whereIn('role', ['admin', 'principal']);
            }

            if ($type === 'personnel_on_leave' && $action === 'delete_all') {
                $query->whereRaw(self::ON_LEAVE_SQL);
            }

            if ($action !== 'delete_all') {
                $query->where('id', $id);
            }

            if ($action === 'restore') {
                return $query->update(['is_deleted' => 0, 'deleted_at' => null, 'deleted_by' => null]);
            }

            if ($softDelete[$type] === 'teachers') {
                $this->archiveAttendanceOf((clone $query)->pluck('id')->all(), $actor);
            }

            return $query->delete();
        }

        $query = DB::table($status[$type])->where('status', 'Inactive');

        if ($status[$type] === 'department_options') {
            $query->where('option_type', self::OPTION_TYPES[$type]);
        }

        if ($type === 'leave_type' && $action !== 'restore') {
            $query->whereRaw(self::LEAVE_TYPE_UNUSED_SQL);
        }

        if ($action !== 'delete_all') {
            $query->where('id', $id);
        }

        return $action === 'restore' ? $query->update(['status' => 'Active']) : $query->delete();
    }

    /**
     * Attendance is never destroyed with a person: it moves to
     * archived_attendance with their name, then the person can be deleted.
     */
    private function archiveAttendanceOf(array $teacherIds, string $actor): void
    {
        if (! $teacherIds) {
            return;
        }

        $teachers = DB::table('teachers')->whereIn('id', $teacherIds)->get(['id', 'fullname', 'department', 'id_number'])->keyBy('id');

        DB::table('attendance')->whereIn('teacher_id', $teacherIds)->orderBy('id')->get()
            ->each(function ($row) use ($teachers, $actor) {
                $t = $teachers[$row->teacher_id];

                DB::table('archived_attendance')->insert([
                    'original_teacher_id' => $row->teacher_id,
                    'fullname' => $t->fullname,
                    'department' => $t->department,
                    'date' => $row->date,
                    'am_arrival' => $row->am_arrival,
                    'am_departure' => $row->am_departure,
                    'pm_arrival' => $row->pm_arrival,
                    'pm_departure' => $row->pm_departure,
                    'attendance_status' => $row->attendance_status,
                    'legacy_id_number' => $t->id_number,
                    'archive_reason' => "Personnel permanently deleted from the recycle bin by {$actor}",
                ]);
            });

        DB::table('attendance')->whereIn('teacher_id', $teacherIds)->delete();
    }

    /** Most recent audit entries (new). */
    /** Rows per page of the Audit Log. */
    public const AUDIT_LOG_PER_PAGE = 25;

    /** One ?page= of the Audit Log, newest first. */
    public function auditLog(Request $request)
    {
        $total = DB::table('audit_logs')->count();
        $pages = Pagination::page((int) $request->query('page', 1), $total, self::AUDIT_LOG_PER_PAGE);

        $entries = DB::table('audit_logs')
            ->orderByDesc('id')
            ->offset($pages['offset'])
            ->limit(self::AUDIT_LOG_PER_PAGE)
            ->get(['id', 'actor', 'action', 'subject_type', 'subject_id', 'details', 'ip_address', 'created_at']);

        return $this->ok(['entries' => $entries, 'total' => $total, 'page' => $pages['page'], 'last_page' => $pages['last_page']]);
    }
}
