<?php

namespace App\Http\Controllers\Data;

use App\Domain\Attendance\AttendanceData;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\OtherChoice;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attendance adjustments (Super Admin). Ported from
 * dtr/get-attendance-adjustments.php, get-single-attendance-adjustment.php,
 * save-attendance-adjustment.php and delete-attendance-adjustment.php.
 *
 * New: approved_by records the Super Admin who saved the adjustment (it was
 * always NULL), and every time is checked to be a real clock time.
 */
class AdjustmentController extends Controller
{
    public const FUTURE_DATE = 'Cannot add an adjustment for a future date.';

    /** Rows per page of the Attendance Adjustments list. */
    public const PER_PAGE = 25;

    /** One ?page= of the list, newest first (a page past the end gives the last page). */
    public function index(Request $request)
    {
        $fmt = fn ($t) => $t ? date('h:i A', strtotime($t)) : null;

        $query = DB::table('attendance_adjustments as aa')
            ->join('teachers as t', 't.id', '=', 'aa.teacher_id')
            ->where('aa.is_deleted', 0);

        $total = (clone $query)->count();
        $pages = Pagination::page((int) $request->query('page', 1), $total, self::PER_PAGE);

        $adjustments = $query
            ->orderByDesc('aa.adjustment_date')
            ->orderByDesc('aa.created_at')
            ->orderByDesc('aa.id')
            ->offset($pages['offset'])
            ->limit(self::PER_PAGE)
            ->get(['aa.*', 't.fullname', 't.id_number'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'teacher_id' => (int) $row->teacher_id,
                'attendance_id' => $row->attendance_id ? (int) $row->attendance_id : null,
                'fullname' => $row->fullname,
                'id_number' => $row->id_number,
                'date' => date('M d, Y', strtotime($row->adjustment_date)),
                'adjustment_date' => $row->adjustment_date,
                'am_arrival' => $fmt($row->am_arrival),
                'am_departure' => $fmt($row->am_departure),
                'pm_arrival' => $fmt($row->pm_arrival),
                'pm_departure' => $fmt($row->pm_departure),
                'reason' => $row->reason,
                'remarks' => $row->remarks,
                'adjustment_type' => $row->adjustment_type,
                'status' => $row->status,
                'approved_by' => $row->approved_by,
                'created_at' => $row->created_at,
            ]);

        return $this->ok([
            'adjustments' => $adjustments,
            'total' => $total,
            'page' => $pages['page'],
            'last_page' => $pages['last_page'],
        ]);
    }

    public function show(Request $request)
    {
        $id = (int) $request->query('id');

        if ($id <= 0) {
            return $this->fail('Invalid adjustment ID.');
        }

        $row = DB::table('attendance_adjustments as aa')
            ->join('teachers as t', 't.id', '=', 'aa.teacher_id')
            ->where('aa.id', $id)
            ->where('aa.is_deleted', 0)
            ->first(['aa.*', 't.fullname', 't.id_number', 't.department']);

        if (! $row) {
            return $this->fail('Attendance adjustment was not found.');
        }

        return $this->ok(['adjustment' => [
            'id' => (int) $row->id,
            'teacher_id' => (int) $row->teacher_id,
            'attendance_id' => $row->attendance_id ? (int) $row->attendance_id : null,
            'fullname' => $row->fullname,
            'id_number' => $row->id_number,
            'department' => $row->department,
            'adjustment_date' => $row->adjustment_date,
            'adjustment_type' => $row->adjustment_type,
            'am_arrival' => $row->am_arrival,
            'am_departure' => $row->am_departure,
            'pm_arrival' => $row->pm_arrival,
            'pm_departure' => $row->pm_departure,
            'reason' => $row->reason,
            'remarks' => $row->remarks,
            'status' => $row->status,
            'approved_by' => $row->approved_by,
            'created_at' => $row->created_at,
        ]]);
    }

    public function store(Request $request)
    {
        $teacherId = (int) $request->input('teacher_id');
        $adjustmentDate = $this->date($request->input('adjustment_date'));
        $adjustmentType = trim((string) $request->input('adjustment_type'));
        $remarks = trim((string) $request->input('remarks'));

        if ($teacherId <= 0) {
            return $this->fail('Please select a valid teacher.');
        }

        if (! $adjustmentDate) {
            return $this->fail('Adjustment date is required.');
        }

        if ($adjustmentDate > date('Y-m-d')) {
            return $this->fail(self::FUTURE_DATE);
        }

        if ($adjustmentType === '') {
            return $this->fail('Attendance issue is required.');
        }

        // "Other" saves the typed issue: "Other: Kiosk was offline".
        [$adjustmentType, $otherError] = OtherChoice::resolve($adjustmentType, $request->input('adjustment_type_other'), 'attendance issue', 100);

        if ($otherError) {
            return $this->fail($otherError);
        }

        // The remarks are the reason for the adjustment (on a leave day too).
        if ($remarks === '') {
            return $this->fail('Remarks are required.');
        }

        $times = $this->times($request);

        if ($times === null) {
            return $this->fail('Please enter valid times.');
        }

        $teacher = DB::table('teachers')->where('id', $teacherId)->first(['id', 'fullname']);

        if (! $teacher) {
            return $this->fail('Selected teacher was not found.');
        }

        $exists = DB::table('attendance_adjustments')
            ->where('teacher_id', $teacherId)
            ->where('adjustment_date', $adjustmentDate)
            ->where('is_deleted', 0)
            ->exists();

        if ($exists) {
            return $this->fail('An attendance adjustment already exists for this teacher on this date.');
        }

        $id = DB::table('attendance_adjustments')->insertGetId([
            'teacher_id' => $teacherId,
            'attendance_id' => DB::table('attendance')->where('teacher_id', $teacherId)->where('date', $adjustmentDate)->value('id'),
            'adjustment_date' => $adjustmentDate,
            'reason' => mb_substr($adjustmentType, 0, 255),
            'remarks' => $remarks,
            'adjustment_type' => mb_substr($adjustmentType, 0, 100),
            'status' => 'Approved',
            'approved_by' => $request->user()->email,
        ] + $times);

        // Allowed on a leave day: the leave is left as it is, and the audit
        // entry records which leave covered the day.
        $leave = AttendanceData::leaveOn($teacherId, $adjustmentDate);

        Audit::log('adjustment.created', 'adjustment', $id, ['teacher_id' => $teacherId, 'date' => $adjustmentDate] + $times + [
            'reason' => $remarks,
        ] + ($leave ? ['on_leave' => ['leave_id' => $leave['id'], 'type' => $leave['leave_type']]] : []));

        return $this->ok([
            'message' => 'Attendance adjustment saved successfully.',
            'adjustment_id' => $id,
            'teacher_id' => $teacherId,
            'fullname' => $teacher->fullname,
            'adjustment_date' => $adjustmentDate,
            'adjustment_type' => $adjustmentType,
        ]);
    }

    /**
     * Review -> Save Changes: an adjustment's four times and its remarks
     * (the reason). The person, date and attendance issue stay as they are;
     * a different person or day is a new adjustment. The same checks as
     * store() apply, and the audit entry keeps the old and new values.
     */
    public function update(Request $request)
    {
        $id = (int) $request->input('id');
        $remarks = trim((string) $request->input('remarks'));

        $adjustment = $id > 0
            ? DB::table('attendance_adjustments')->where('id', $id)->where('is_deleted', 0)
                ->first(['id', 'teacher_id', 'adjustment_date', 'am_arrival', 'am_departure', 'pm_arrival', 'pm_departure', 'remarks'])
            : null;

        if (! $adjustment) {
            return $this->fail('Attendance adjustment was not found.');
        }

        if ($adjustment->adjustment_date > date('Y-m-d')) {
            return $this->fail(self::FUTURE_DATE);
        }

        if ($remarks === '') {
            return $this->fail('Remarks are required.');
        }

        $times = $this->times($request);

        if ($times === null) {
            return $this->fail('Please enter valid times.');
        }

        $new = $times + ['remarks' => $remarks];
        $changed = array_filter($new, fn ($value, $field) => $value !== $adjustment->{$field}, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            return $this->ok(['message' => 'No changes to save.', 'adjustment_id' => $id]);
        }

        DB::table('attendance_adjustments')->where('id', $id)->where('is_deleted', 0)->update($changed);

        Audit::log('adjustment.updated', 'adjustment', $id, [
            'teacher_id' => (int) $adjustment->teacher_id,
            'date' => $adjustment->adjustment_date,
            'before' => array_intersect_key((array) $adjustment, $changed),
            'after' => $changed,
        ]);

        return $this->ok(['message' => 'Attendance adjustment updated.', 'adjustment_id' => $id]);
    }

    /**
     * The four time fields as H:i:s (null when empty), or null when any is
     * not a real clock time.
     *
     * @return ?array{am_arrival: ?string, am_departure: ?string, pm_arrival: ?string, pm_departure: ?string}
     */
    private function times(Request $request): ?array
    {
        $times = [];

        foreach (['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'] as $field) {
            $value = trim((string) $request->input($field));

            if ($value !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) {
                return null;
            }

            $times[$field] = $value === '' ? null : (strlen($value) === 5 ? $value.':00' : $value);
        }

        return $times;
    }

    public function destroy(Request $request)
    {
        $id = (int) $request->input('id');

        if ($id <= 0) {
            return $this->fail('Invalid adjustment ID.');
        }

        $changed = DB::table('attendance_adjustments')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->update(['is_deleted' => 1, 'deleted_at' => now(), 'deleted_by' => $request->user()->email]);

        if ($changed === 0) {
            return $this->fail('Adjustment not found.');
        }

        Audit::log('adjustment.deleted', 'adjustment', $id);

        return $this->ok(['message' => 'Attendance adjustment moved to Recycle Bin.']);
    }
}
