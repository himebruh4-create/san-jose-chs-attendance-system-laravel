<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Attendance\ScanPhotos;
use App\Domain\Attendance\ScanVerificationCounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Scan Photos page (new): every webcam photo the kiosks took on a day, next
 * to the person's registered photo, so the Super Admin can check who
 * actually scanned. Images are served by PhotoController.
 */
class ScanPhotosController extends Controller
{
    private const PER_PAGE = 48;

    /** Outcome filter => what it matches. */
    public const OUTCOME_FILTERS = [
        'all' => 'All scans',
        'accepted' => 'Accepted',
        'not_accepted' => 'Not accepted',
        'not_found' => 'Unknown barcode',
    ];

    /** Scanner outcome => label shown on the card. */
    public const OUTCOME_LABELS = [
        'accepted' => 'Accepted',
        'duplicate' => 'Duplicate',
        'complete' => 'Already complete',
        'not_found' => 'Unknown barcode',
        'recording_disabled' => 'Recording off',
        'invalid' => 'Invalid',
        'error' => 'Error',
        'rejected' => 'Rejected',
    ];

    public const KIND_LABELS = [
        'IN' => 'Time in',
        'OUT' => 'Time out',
        'BREAK_OUT' => 'Lunch out',
        'BREAK_IN' => 'Lunch in',
    ];

    public function show(Request $request): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'teacher_id' => ['nullable', 'integer'],
            'outcome' => ['nullable', 'in:'.implode(',', array_keys(self::OUTCOME_FILTERS))],
        ]);

        $date = Carbon::parse($filters['date'] ?? today()->toDateString());
        $teacherId = $filters['teacher_id'] ?? null;
        $outcome = $filters['outcome'] ?? 'all';

        $dayQuery = DB::table('scan_photos')->where('attendance_date', $date->toDateString());

        $summary = [
            'total' => (clone $dayQuery)->count(),
            'withPhoto' => (clone $dayQuery)->whereNotNull('path')->count(),
            'notAccepted' => (clone $dayQuery)->where('outcome', '!=', 'accepted')->count(),
        ];

        $photos = DB::table('scan_photos as p')
            ->leftJoin('teachers as t', 't.id', '=', 'p.teacher_id')
            ->leftJoin('kiosk_devices as k', 'k.id', '=', 'p.kiosk_device_id')
            ->where('p.attendance_date', $date->toDateString())
            ->when($teacherId, fn ($query) => $query->where('p.teacher_id', $teacherId))
            ->when($outcome === 'accepted', fn ($query) => $query->where('p.outcome', 'accepted'))
            ->when($outcome === 'not_accepted', fn ($query) => $query->where('p.outcome', '!=', 'accepted'))
            ->when($outcome === 'not_found', fn ($query) => $query->where('p.outcome', 'not_found'))
            ->orderByDesc('p.scanned_at')
            ->orderByDesc('p.id')
            ->select(['p.id', 'p.teacher_id', 'p.barcode', 'p.scanned_at', 'p.outcome', 'p.scan_kind', 'p.input_method', 'p.path',
                't.fullname', 't.id_number', 't.photo', 'k.name as kiosk_name'])
            ->selectRaw(ScanVerificationCounts::rapidScanCondition('p').' AS rapid_scan')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $photos->through(fn ($p) => [
            'id' => $p->id,
            'name' => $p->fullname ?? ($p->barcode !== null ? 'Unknown barcode '.$p->barcode : 'Unknown'),
            'id_number' => $p->id_number,
            'time' => Carbon::parse($p->scanned_at)->format('g:i:s A'),
            'kind' => self::KIND_LABELS[$p->scan_kind] ?? null,
            'outcome' => $p->outcome,
            'outcome_label' => self::OUTCOME_LABELS[$p->outcome] ?? ucfirst(str_replace('_', ' ', $p->outcome)),
            'manual_entry' => $p->input_method === ScanPhotos::INPUT_TYPED,
            'rapid_scan' => (bool) $p->rapid_scan,
            'kiosk' => $p->kiosk_name,
            'url' => $p->path ? route('photos.scan', $p->id) : null,
            'registered_photo' => $p->photo ? route('photos.personnel', basename($p->photo)) : null,
        ]);

        $teachers = DB::table('teachers')->orderBy('fullname')->get(['id', 'fullname', 'is_deleted']);

        return view('superadmin.scan-photos', [
            'photos' => $photos,
            'summary' => $summary,
            'teachers' => $teachers,
            'date' => $date,
            'teacherId' => $teacherId,
            'outcome' => $outcome,
            'retentionDays' => ScanPhotos::RETENTION_DAYS,
        ]);
    }
}
