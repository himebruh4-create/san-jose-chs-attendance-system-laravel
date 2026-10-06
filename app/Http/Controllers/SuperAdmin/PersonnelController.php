<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Attendance\BreakConfig;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Data\SchoolEventController;
use App\Models\Teacher;
use App\Support\Audit;
use App\Support\PersonnelPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Personnel Management (Super Admin). Ported from superadmin/super-admin.php.
 *
 * Changes: photos are always re-encoded and stored privately; a duplicate
 * ID number or barcode gets a clear message; names keep the casing typed
 * (the old lower-case/re-capitalize step broke names like "II"); every save
 * is one transaction, so a failed schedule never leaves a half-saved person.
 */
class PersonnelController extends Controller
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private const PER_PAGE = 5;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $filterAcademic = trim((string) $request->query('academic_status'));
        $filterEmployment = trim((string) $request->query('employment_type'));

        $query = Teacher::query()->active()
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('fullname', 'like', "%{$search}%")->orWhere('id_number', 'like', "%{$search}%")))
            ->when($filterAcademic !== '', fn ($q) => $q->where('academic_status', $filterAcademic))
            ->when($filterEmployment !== '', fn ($q) => $q->where('employment_type', $filterEmployment));

        $total = (clone $query)->count();
        $totalPages = $total > 0 ? (int) ceil($total / self::PER_PAGE) : 1;
        $page = min(max(1, (int) $request->query('page', 1)), $totalPages);

        $teachers = $query->orderBy('created_at')->orderBy('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['id', 'id_number', 'fullname', 'department', 'academic_status', 'employment_type', 'barcode', 'photo', 'created_at'])
            ->map(function ($t) {
                $row = $t->toArray();
                $row['schedules'] = DB::table('teacher_schedules')
                    ->where('teacher_id', $t->id)
                    ->orderByRaw("FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')")
                    ->get(['day', 'time_in', 'time_out'])
                    ->map(fn ($s) => (array) $s)
                    ->all();

                return $row;
            })
            ->all();

        $allTeachers = Teacher::query()->active()->orderBy('fullname')
            ->get(['id', 'id_number', 'fullname', 'department', 'barcode', 'photo'])
            ->map(fn ($t) => $t->only(['id', 'id_number', 'fullname', 'department', 'barcode', 'photo']))
            ->all();

        $today = date('Y-m-d');

        $teachersOnLeave = DB::table('teacher_leaves as tl')
            ->join('teachers as t', 't.id', '=', 'tl.teacher_id')
            ->where('tl.status', 'Approved')
            ->where('t.is_deleted', 0)
            ->where('tl.leave_from', '<=', $today)
            ->where('tl.leave_until', '>=', $today)
            ->orderBy('tl.leave_from')
            ->get(['tl.id as leave_id', 'tl.teacher_id', 'tl.leave_from', 'tl.leave_until', 'tl.leave_type',
                'tl.status', 'tl.reason', 't.id_number', 't.fullname', 't.department']);

        return response()->view('superadmin.personnel', [
            'search' => $search,
            'filterAcademic' => $filterAcademic,
            'filterEmployment' => $filterEmployment,
            'teachers' => $teachers,
            'allTeachers' => $allTeachers,
            'page' => $page,
            'limit' => self::PER_PAGE,
            'totalPages' => $totalPages,
            'teachersOnLeave' => $teachersOnLeave,
            'scheduleDays' => self::DAYS,
            'breakConfig' => BreakConfig::load(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function save(Request $request)
    {
        $id = (int) $request->input('id');
        $errors = [];

        $existing = $id > 0 ? Teacher::query()->find($id) : null;

        if ($id > 0 && ! $existing) {
            return $this->back($request, 'Personnel not found.', 'error');
        }

        $name = SchoolEventController::cleanName((string) $request->input('name'));
        $department = trim((string) $request->input('department'));
        $academic = trim((string) $request->input('academic_status'));
        $employment = trim((string) $request->input('employment_type'));
        $idNumber = trim((string) $request->input('id_number'));
        $barcode = trim((string) $request->input('barcode'));

        if ($name === '' || $department === '' || $academic === '' || $employment === '') {
            $errors[] = 'Please fill in all required fields.';
        }

        if (! in_array($academic, ['', 'Academic', 'Non-Academic'], true) || ! in_array($employment, ['', 'Regular', 'Part-Time', 'Contractual'], true)) {
            $errors[] = 'Invalid personnel type or employment type.';
        }

        if ($barcode !== '' && ! preg_match('/^[A-Za-z0-9-]{1,100}$/', $barcode)) {
            $errors[] = 'The barcode may only contain letters, numbers and dashes.';
        }

        // Schedule (Monday–Saturday)
        $schedules = [];
        foreach (self::DAYS as $day) {
            $key = strtolower($day);
            $row = $request->input("schedule.{$key}", []);

            if (! is_array($row) || empty($row['enabled'])) {
                continue;
            }

            $in = trim((string) ($row['time_in'] ?? ''));
            $out = trim((string) ($row['time_out'] ?? ''));

            if ($in === '' || $out === '') {
                $errors[] = "{$day} schedule requires both time-in and time-out.";
            } elseif (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $in) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $out)) {
                $errors[] = "{$day} schedule has an invalid time.";
            } else {
                $schedules[] = ['day' => $day, 'time_in' => $in, 'time_out' => $out];
            }
        }

        if ($idNumber !== '' && Teacher::query()->where('id_number', $idNumber)->when($existing, fn ($q) => $q->where('id', '<>', $existing->id))->exists()) {
            $errors[] = "ID Number {$idNumber} is already used by another personnel record.";
        }

        if ($barcode !== '' && Teacher::query()->where('barcode', $barcode)->when($existing, fn ($q) => $q->where('id', '<>', $existing->id))->exists()) {
            $errors[] = "Barcode {$barcode} is already used by another personnel record.";
        }

        $photoName = null;

        if (! $errors && $request->hasFile('photo')) {
            $file = $request->file('photo');

            if (! $file->isValid()) {
                $errors[] = 'Image upload failed.';
            } elseif ($file->getSize() > 3 * 1024 * 1024) {
                $errors[] = 'Image must be 3MB or smaller.';
            } elseif (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors[] = 'Invalid image format.';
            } else {
                $photoName = PersonnelPhotos::store($file->getRealPath());
                if ($photoName === null) {
                    $errors[] = 'Image processing failed.';
                }
            }
        }

        if ($errors) {
            return redirect()->to($this->returnUrl($request))->withErrors($errors);
        }

        $values = [
            'id_number' => $idNumber !== '' ? $idNumber : Teacher::nextIdNumber(),
            'fullname' => mb_substr($name, 0, 150),
            'department' => mb_substr($department, 0, 100),
            'academic_status' => $academic,
            'employment_type' => $employment,
            'barcode' => $barcode !== '' ? $barcode : Teacher::newBarcode(),
        ];

        if ($photoName) {
            $values['photo'] = $photoName;
        }

        $oldPhoto = $existing?->photo;

        $teacher = DB::transaction(function () use ($existing, $values, $schedules) {
            $teacher = $existing ?? new Teacher;
            $teacher->fill($values)->save();

            DB::table('teacher_schedules')->where('teacher_id', $teacher->id)->delete();
            DB::table('teacher_schedules')->insert(array_map(fn ($s) => $s + ['teacher_id' => $teacher->id], $schedules));

            return $teacher;
        });

        // The replaced photo file is no longer referenced by anyone.
        if ($photoName && $oldPhoto) {
            PersonnelPhotos::delete($oldPhoto);
        }

        Audit::log($existing ? 'personnel.updated' : 'personnel.created', 'teacher', $teacher->id, $values + ['schedules' => $schedules]);

        return $this->back($request, $existing ? 'Personnel updated successfully.' : 'Personnel added successfully.');
    }

    public function archive(Request $request)
    {
        $id = (int) $request->input('id');

        if ($id <= 0) {
            return $this->back($request, 'Invalid teacher ID.', 'error');
        }

        $changed = DB::table('teachers')->where('id', $id)->where('is_deleted', 0)
            ->update(['is_deleted' => 1, 'deleted_at' => now(), 'deleted_by' => $request->user()->email]);

        if (! $changed) {
            return $this->back($request, 'Personnel not found.', 'error');
        }

        Audit::log('personnel.archived', 'teacher', $id);

        return $this->back($request, 'Personnel moved to Recycle Bin.');
    }

    public function regenerateBarcode(Request $request)
    {
        $teacher = Teacher::query()->find((int) $request->input('id'));

        if (! $teacher) {
            return $this->back($request, 'Invalid teacher ID.', 'error');
        }

        $old = $teacher->barcode;
        $teacher->forceFill(['barcode' => Teacher::newBarcode()])->save();

        Audit::log('personnel.barcode_regenerated', 'teacher', $teacher->id, ['old' => $old, 'new' => $teacher->barcode]);

        return $this->back($request, 'Barcode regenerated successfully! New barcode: '.$teacher->barcode);
    }

    public function addLeave(Request $request)
    {
        $teacherId = (int) $request->input('leave_teacher_id');
        $from = $this->date($request->input('leave_from'));
        $until = $this->date($request->input('leave_until'));
        $type = trim((string) $request->input('leave_type'));
        $reason = trim((string) $request->input('leave_reason'));

        $errors = [];

        if ($teacherId <= 0 || ! Teacher::query()->active()->whereKey($teacherId)->exists()) {
            $errors[] = 'Please select a teacher.';
        }
        if (! $from) {
            $errors[] = 'Please select the leave start date.';
        }
        if (! $until) {
            $errors[] = 'Please select the leave end date.';
        }
        if ($from && $until && $from > $until) {
            $errors[] = 'Leave end date cannot be earlier than the start date.';
        }
        if ($type === '') {
            $errors[] = 'Please select a leave type.';
        }

        // An Approved leave already covering any part of the range.
        if ($teacherId > 0 && $from && $until && DB::table('teacher_leaves')
            ->where('teacher_id', $teacherId)->where('status', 'Approved')
            ->where('leave_from', '<=', $until)->where('leave_until', '>=', $from)->exists()) {
            $errors[] = 'This personnel already has an approved leave that overlaps these dates.';
        }

        if ($errors) {
            return $this->back($request, implode(' ', $errors), 'error');
        }

        $leaveId = DB::table('teacher_leaves')->insertGetId([
            'teacher_id' => $teacherId,
            'leave_from' => $from,
            'leave_until' => $until,
            'leave_type' => mb_substr($type, 0, 100),
            'reason' => $reason,
            'status' => 'Approved',
        ]);

        Audit::log('leave.created', 'teacher', $teacherId, ['leave_id' => $leaveId, 'from' => $from, 'until' => $until, 'type' => $type]);

        return $this->back($request, 'Personnel leave added successfully.');
    }

    /** Back to the list, keeping its search / filters / page. */
    private function back(Request $request, string $message, string $type = 'success')
    {
        return redirect()->to($this->returnUrl($request))
            ->with('message', $message)
            ->with('message_type', $type);
    }

    private function returnUrl(Request $request): string
    {
        $query = array_filter([
            'search' => $request->input('return_search'),
            'academic_status' => $request->input('return_academic_status'),
            'employment_type' => $request->input('return_employment_type'),
            'page' => $request->input('return_page'),
        ], fn ($v) => $v !== null && $v !== '');

        return route('superadmin.personnel', $query);
    }
}
