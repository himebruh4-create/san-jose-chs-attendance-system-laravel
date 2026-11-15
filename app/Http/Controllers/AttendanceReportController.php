<?php

namespace App\Http\Controllers;

use App\Domain\Attendance\DtrPersonnel;
use App\Domain\PersonnelDirectory;
use App\Support\Printout;
use Illuminate\Http\Request;

/**
 * Attendance Report: the list of personnel for a month, each with their
 * Generate DTR button, plus Generate All DTR and the Summary Report.
 * Shared by Super Admin and Admin (was monthly-report-superadmin.php and
 * admin-monthly-report.php, which were copies of each other).
 *
 * The native-PHP pages also computed on-time / late / absent counts with
 * the old fixed 8:00 / 1:00 cutoffs in a GROUP BY query that fails on a
 * strict MySQL server; nothing displayed them, so they are gone. The DTR
 * itself (public/js/dtr-view.js) only needs each person's id, name and
 * raw records.
 */
abstract class AttendanceReportController extends Controller
{
    private const PER_PAGE = 5;

    abstract protected function role(): string;

    public function show(Request $request)
    {
        [$year, $month] = $this->selectedMonth($request);

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $search = trim((string) $request->query('search'));
        $personnelType = PersonnelDirectory::typeTab($request->query('academic_status'));

        $query = DtrPersonnel::query($search, $personnelType);

        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->query('page', 1)), $totalPages);

        $account = $request->user();
        $pageTeachers = (clone $query)->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get(DtrPersonnel::columns());

        return view('attendance-report', [
            'role' => $this->role(),
            'search' => $search,
            'month' => $month,
            'year' => $year,
            'monthStart' => $monthStart,
            'monthEnd' => $monthEnd,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalTeachers' => $total,
            // This page's people only; each card's own month picker reloads
            // just that card's data (/data/dtr-month).
            'teacherReports' => DtrPersonnel::payloads($pageTeachers, $monthStart, $account),
            // Generate All DTR reads every matching person's data from here,
            // in one request, only when the button is pressed.
            'allDtrUrl' => route('data.dtr-bundle', array_filter([
                'month' => sprintf('%04d-%02d', $year, $month), 'search' => $search, 'academic_status' => $personnelType,
            ], fn ($v) => $v !== '')),
            // "DTR - Teaching Staff - 2026-10 - Administrative Officer": the selected tab.
            'allDtrPrint' => Printout::job($account, 'DTR', Printout::month($monthStart), PersonnelDirectory::TYPE_TABS[$personnelType], personnelType: $personnelType ?: null),
            'personnelType' => $personnelType,
        ]);
    }

    /** ?monthPicker=YYYY-MM, or ?month=&year=, or the current month. */
    private function selectedMonth(Request $request): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('monthPicker'), $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } else {
            $year = (int) $request->query('year', date('Y'));
            $month = (int) $request->query('month', date('m'));
        }

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return [(int) date('Y'), (int) date('m')];
        }

        return [$year, $month];
    }
}
