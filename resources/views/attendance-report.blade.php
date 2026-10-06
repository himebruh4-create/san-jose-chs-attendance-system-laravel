@extends('layouts.app')

@section('title', $role === 'superadmin' ? 'Attendance Report - Admin Aide' : 'Attendance Report - Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/attendance-report.css') }}">
@endpush

@php
    $reportRoute = $role.'.attendance-report';
    $monthValue = sprintf('%04d-%02d', $year, $month);
    $listQuery = array_filter(['search' => $search, 'monthPicker' => $monthValue], fn ($v) => $v !== '');
    $pageUrl = fn ($p) => route($reportRoute, $listQuery + ['page' => $p]);
@endphp

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">{{ $role === 'superadmin' ? 'Admin Aide' : 'Admin' }}</div>
        <h1>Attendance Report</h1>
        <p>Review this month's attendance and generate personnel DTRs.</p>
    </div>

    <div class="summary-box">
        <div class="summary-icon"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="summary-stats">
            <div>
                <div class="summary-stat-label">Month Covered</div>
                <div class="summary-stat-value">{{ $monthStart }} &nbsp;&rarr;&nbsp; {{ $monthEnd }}</div>
            </div>
            <div>
                <div class="summary-stat-label">Total Personnel</div>
                <div class="summary-stat-value">{{ $totalTeachers }}</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="search-bar-row">
            <form method="GET" action="{{ route($reportRoute) }}" style="display: flex; gap: 10px; align-items: center; flex: 1; flex-wrap: wrap;">
                <input type="hidden" name="monthPicker" value="{{ $monthValue }}">
                <input type="text" name="search" placeholder="Search by name or ID" value="{{ $search }}">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                @if ($search !== '')
                    <a href="{{ route($reportRoute, ['monthPicker' => $monthValue]) }}" class="btn btn-outline">Clear</a>
                @endif
            </form>
        </div>
    </div>

    <div class="modal-backdrop" id="chartModal">
        <div class="modal">
            <button class="modal-close" onclick="closeChartModal()">&times;</button>
            <h3 id="chartModalTitle"></h3>
            <div id="chartModalContent"></div>
        </div>
    </div>

    <!-- APPLICATION ERROR MODAL -->
    <div class="modal-backdrop" id="appErrorModal">
        <div class="modal">
            <button class="modal-close" onclick="closeErrorModal()">&times;</button>
            <h3>Something Went Wrong</h3>
            <p id="appErrorModalMessage" style="color:#8a7d5c; font-size:14px; margin:0;"></p>
        </div>
    </div>

    <div class="card">
        <form method="GET" action="{{ route($reportRoute) }}" class="month-picker-row">
            <label>Select Month:</label>
            <input type="month" name="monthPicker" value="{{ $monthValue }}" onchange="this.form.submit()">
            <input type="hidden" name="search" value="{{ $search }}">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Apply</button>
        </form>
    </div>

    <div class="card">

        <div class="report-header-row">
            <h3><i class="fa-solid fa-list-check"></i> Personnel</h3>
            <span class="page-tag">Page {{ $page }} of {{ $totalPages }}</span>
        </div>

        <button class="generate-report-btn" onclick="printAllDTR(allTeachersForDTR, {{ Js::from($monthStart) }}, {{ Js::from($monthEnd) }})" style="margin-top: 10px;">
            <i class="fa-solid fa-file-export"></i> Generate All DTR
        </button>

        <div class="teacher-list">
            @foreach ($teacherReports as $teacher)
                <div class="teacher-item">
                    <div class="teacher-header">
                        <strong>{{ $teacher['fullname'] }}</strong>

                        <input type="month" class="monthPicker" value="{{ $monthValue }}" onchange="changeTeacherMonth(this.value)">

                        <button class="dtr-btn" data-teacher="{{ json_encode($teacher) }}"
                            onclick="printIndividualReport(JSON.parse(this.dataset.teacher))">
                            <i class="fa-solid fa-file-lines"></i> Generate DTR
                        </button>
                    </div>
                    <div class="teacher-meta">
                        <div>
                            <b>ID Number</b>
                            {{ $teacher['id_number'] }}
                        </div>
                        <div>
                            <b>Department</b>
                            {{ $teacher['department'] }}
                        </div>
                        <div>
                            <b>Personnel Type</b>
                            {{ $teacher['academic_status'] === 'Academic' ? 'Teaching Staff' : 'Non-Teaching Staff / School Administrator' }}
                        </div>
                        <div>
                            <b>Employment</b>
                            {{ $teacher['employment_type'] }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="pagination">
            @if ($page > 1)
                <a href="{{ $pageUrl(1) }}">&laquo; First</a>
                <a href="{{ $pageUrl($page - 1) }}">&lsaquo; Previous</a>
            @endif

            @for ($i = 1; $i <= $totalPages; $i++)
                @if ($i == $page)
                    <span class="active">{{ $i }}</span>
                @else
                    <a href="{{ $pageUrl($i) }}">{{ $i }}</a>
                @endif
            @endfor

            @if ($page < $totalPages)
                <a href="{{ $pageUrl($page + 1) }}">Next &rsaquo;</a>
                <a href="{{ $pageUrl($totalPages) }}">Last &raquo;</a>
            @endif
        </div>

        <button class="generate-report-btn" onclick="printSummaryReport({{ (int) $month }}, {{ (int) $year }})" style="margin-top:14px;">
            <i class="fa-solid fa-file-export"></i> Generate Summary Report
        </button>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Every matching person with their records, for Generate All DTR —
    // the same per-person DTR template as an individual Generate DTR.
    const allTeachersForDTR = @json($allTeachersForDTR);

    async function printSummaryReport(month, year) {
        try {
            const response = await fetch(appUrl('reports/monthly-summary') + `?month=${month}&year=${year}`);
            if (!response.ok) throw new Error('HTTP ' + response.status);
            printHTMLDocument(await response.text());
        } catch (error) {
            console.error("Generate Summary Report error:", error);
            showErrorModal("Unable to generate the summary report.");
        }
    }

    function showErrorModal(message) {
        document.getElementById('appErrorModalMessage').textContent = message || 'An error occurred.';
        document.getElementById('appErrorModal').classList.add('active');
        lockBodyScroll();
    }

    function closeErrorModal() {
        document.getElementById('appErrorModal').classList.remove('active');
        unlockBodyScroll();
    }

    function changeTeacherMonth(monthValue) {
        const url = new URL(window.location.href);
        url.searchParams.set('monthPicker', monthValue);
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    }
</script>
<script src="{{ asset_v('js/dtr-view.js') }}"></script>
@endpush
