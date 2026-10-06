<?php

namespace App\Http\Controllers\Data;

use App\Domain\Attendance\AttendanceData;
use App\Domain\Attendance\BreakConfig;
use App\Domain\Attendance\PendingReview;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use function App\Domain\Attendance\dtrBreakForSchedule;
use function App\Domain\Attendance\dtrDayCalc;
use function App\Domain\Attendance\dtrReviewPeriodStart;
use function App\Domain\Attendance\dtrRulesApply;

/**
 * The Super Admin attendance-review workflow. Ported from
 * dtr/get-review-summary.php, get-pending-review.php,
 * dismiss-review-reminder.php, confirm-absent.php, get-day-real-scans.php,
 * get-rejected-scans.php and review-rejected-scan.php.
 */
class ReviewController extends Controller
{
    public function summary()
    {
        return $this->ok(PendingReview::summary());
    }

    public function pending(Request $request)
    {
        $items = PendingReview::compute(['teacher_id' => (int) $request->query('teacher_id')]);
        $groups = PendingReview::groupByPeriod($items);

        return $this->ok([
            'pending' => $items,
            'current' => $groups['current'],
            'previous' => $groups['previous'],
            'counts' => $groups['counts'],
        ]);
    }

    public function dismissReminder()
    {
        $period = dtrReviewPeriodStart(date('Y-m-d'));

        DB::table('system_settings')->where('id', 1)->update(['review_reminder_dismissed_period' => $period]);

        return $this->ok(['dismissed_period' => $period]);
    }

    public function confirmAbsent(Request $request)
    {
        $teacherId = (int) $request->input('teacher_id');
        $date = $this->date($request->input('date'));

        if ($teacherId <= 0 || ! $date) {
            return $this->fail('Personnel and date are required.');
        }

        if (! DB::table('teachers')->where('id', $teacherId)->exists()) {
            return $this->fail('Personnel not found.');
        }

        DB::table('confirmed_absences')->upsert(
            [['teacher_id' => $teacherId, 'absence_date' => $date, 'confirmed_by' => $request->user()->email, 'confirmed_at' => now()]],
            ['teacher_id', 'absence_date'],
            ['confirmed_by', 'confirmed_at']
        );

        Audit::log('absence.confirmed', 'teacher', $teacherId, ['date' => $date]);

        return $this->ok(['message' => 'Absence confirmed.']);
    }

    /** What the scanner actually recorded for one person on one date. */
    public function dayRealScans(Request $request)
    {
        $teacherId = (int) $request->query('teacher_id');
        $date = $this->date($request->query('date'));

        if ($teacherId <= 0 || ! $date) {
            return $this->fail('Personnel and a valid date are required.');
        }

        $real = DB::table('attendance')->where('teacher_id', $teacherId)->where('date', $date)
            ->first(['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure']);
        $real = $real ? (array) $real : null;

        $schedule = AttendanceData::scheduleFor($teacherId, $date);
        $break = dtrBreakForSchedule(BreakConfig::load(), $schedule);
        $calc = ($schedule || $real) ? dtrDayCalc($schedule, $real ?: [], null, []) : null;

        $photos = DB::table('scan_photos')->where('teacher_id', $teacherId)->where('attendance_date', $date)
            ->orderBy('scanned_at')->get(['id', 'scanned_at', 'outcome', 'scan_kind', 'path'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'scanned_at' => $p->scanned_at,
                'outcome' => $p->outcome,
                'scan_kind' => $p->scan_kind,
                'url' => $p->path ? route('photos.scan', $p->id) : null,
            ]);

        return $this->ok([
            'date' => $date,
            'rules_apply' => dtrRulesApply($date),
            'real' => $real,
            'schedule' => $schedule,
            'break' => $break,
            'in' => $calc['in'] ?? '',
            'out' => $calc['out'] ?? '',
            'missing_out' => $calc['missing_out'] ?? false,
            'overtime' => $calc['overtime'] ?? false,
            'early_departure' => $calc['early_departure'] ?? false,
            'scan_photos' => $photos,
            'registered_photo' => ($p = DB::table('teachers')->where('id', $teacherId)->value('photo'))
                ? route('photos.personnel', basename($p)) : null,
        ]);
    }

    public function rejectedScans(Request $request)
    {
        $filter = $request->query('status', 'all');   // all | unreviewed | reviewed

        $query = DB::table('attendance_rejected_scans as r')
            ->leftJoin('teachers as t', 't.id', '=', 'r.teacher_id')
            ->select('r.id', 'r.teacher_id', 'r.attempted_at', 'r.attendance_date', 'r.reason', 'r.detail',
                'r.existing_out_time', 'r.reviewed_at', 'r.reviewed_by', 'r.review_remarks',
                't.fullname', 't.id_number', 't.department')
            ->orderByDesc('r.attempted_at')
            ->orderByDesc('r.id')
            ->limit(500);

        if ($filter === 'unreviewed') {
            $query->whereNull('r.reviewed_at');
        } elseif ($filter === 'reviewed') {
            $query->whereNotNull('r.reviewed_at');
        }

        $scans = $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'teacher_id' => (int) $row->teacher_id,
            'fullname' => $row->fullname ?: ('Personnel #'.$row->teacher_id.' (removed)'),
            'id_number' => $row->id_number,
            'department' => $row->department,
            'attempted_at' => $row->attempted_at,
            'attendance_date' => $row->attendance_date,
            'reason' => $row->reason,
            'detail' => $row->detail,
            'existing_out' => $row->existing_out_time,
            'reviewed_at' => $row->reviewed_at,
            'reviewed_by' => $row->reviewed_by,
            'review_remarks' => $row->review_remarks,
        ]);

        return $this->ok([
            'scans' => $scans,
            'unreviewed' => DB::table('attendance_rejected_scans')->whereNull('reviewed_at')->count(),
        ]);
    }

    public function reviewRejectedScan(Request $request)
    {
        $id = (int) $request->input('id');
        $remarks = trim((string) $request->input('remarks'));

        if ($id <= 0) {
            return $this->fail('Invalid entry.');
        }

        if (mb_strlen($remarks) > 255) {
            return $this->fail('Remarks are too long (255 characters maximum).');
        }

        $changed = DB::table('attendance_rejected_scans')
            ->where('id', $id)
            ->whereNull('reviewed_at')
            ->update([
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->email,
                'review_remarks' => $remarks === '' ? null : $remarks,
            ]);

        if ($changed < 1) {
            return $this->fail('This entry was already reviewed or does not exist.');
        }

        Audit::log('rejected_scan.reviewed', 'rejected_scan', $id);

        return $this->ok(['message' => 'Marked as reviewed.']);
    }

    /** Latest kiosk photos (new): verification log for the Super Admin. */
    public function scanPhotos(Request $request)
    {
        $date = $this->date($request->query('date')) ?? date('Y-m-d');

        $photos = DB::table('scan_photos as p')
            ->leftJoin('teachers as t', 't.id', '=', 'p.teacher_id')
            ->where('p.attendance_date', $date)
            ->orderByDesc('p.scanned_at')
            ->limit(500)
            ->get(['p.id', 'p.teacher_id', 'p.barcode', 'p.scanned_at', 'p.outcome', 'p.scan_kind', 'p.path', 't.fullname', 't.photo'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'teacher_id' => $p->teacher_id,
                'fullname' => $p->fullname ?? ($p->barcode ? 'Unknown barcode '.$p->barcode : 'Unknown'),
                'scanned_at' => $p->scanned_at,
                'outcome' => $p->outcome,
                'scan_kind' => $p->scan_kind,
                'url' => $p->path ? route('photos.scan', $p->id) : null,
                'registered_photo' => $p->photo ? route('photos.personnel', basename($p->photo)) : null,
            ]);

        return $this->ok(['date' => $date, 'photos' => $photos]);
    }
}
