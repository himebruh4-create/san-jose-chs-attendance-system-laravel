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
        <div class="eyebrow">{{ auth()->user()->roleLabel() }}</div>
        <h1>Attendance Report</h1>
        <p>Review this month's attendance and generate personnel DTRs.</p>
    </div>

    <div class="card">
        {{-- Applies itself (live-filters.js): the search as you type, the month on change. --}}
        <form method="GET" action="{{ route($reportRoute) }}" class="search-bar-row month-picker-row" data-live-filter data-live-target="#reportSummary, #reportPersonnel">
            <label for="reportSearch">Search:</label>
            <input type="text" id="reportSearch" name="search" placeholder="Search by name or ID" value="{{ $search }}" autocomplete="off">
            <label for="reportMonth">Month:</label>
            <input type="month" id="reportMonth" name="monthPicker" value="{{ $monthValue }}">
            <noscript><button type="submit" class="btn btn-primary">Apply</button></noscript>
        </form>
    </div>

    <div class="summary-box" id="reportSummary">
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

    <div class="modal-backdrop" id="chartModal">
        <div class="modal">
            <button class="modal-close" onclick="closeChartModal()">&times;</button>
            <h3 id="chartModalTitle"></h3>
            <div id="chartModalContent"></div>
        </div>
    </div>

    <div class="card" id="reportPersonnel">

        <div class="report-header-row">
            <h3><i class="fa-solid fa-list-check"></i> Personnel</h3>
            <span class="page-tag">Page {{ $page }} of {{ $totalPages }}</span>
        </div>

        {{-- Every person matching the current search, for Generate All DTR (the
             same per-person DTR as Generate DTR). Kept inside this card so a
             live search replaces it together with the list. --}}
        <script type="application/json" id="allTeachersForDTR">@json($allTeachersForDTR)</script>

        <button class="generate-report-btn" onclick="printAllDTR(JSON.parse(document.getElementById('allTeachersForDTR').textContent), {{ Js::from($monthStart) }}, {{ Js::from($monthEnd) }}, {{ Js::from($allDtrPrint) }})" style="margin-top: 10px;">
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
    </div>
</div>
@endsection

@push('scripts')
<script>
    function changeTeacherMonth(monthValue) {
        const url = new URL(window.location.href);
        url.searchParams.set('monthPicker', monthValue);
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    }
</script>
<script src="{{ asset_v('js/dtr-view.js') }}"></script>
<script src="{{ asset_v('js/live-filters.js') }}"></script>
@endpush
