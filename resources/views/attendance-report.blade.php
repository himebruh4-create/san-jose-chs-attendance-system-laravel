@extends('layouts.app')

@section('title', $role === 'superadmin' ? 'Attendance Report - Admin Aide' : 'Attendance Report - Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/attendance-report.css') }}">
@endpush

@php
    $reportRoute = $role.'.attendance-report';
    $monthValue = sprintf('%04d-%02d', $year, $month);
    $listQuery = array_filter(['search' => $search, 'monthPicker' => $monthValue, 'academic_status' => $personnelType], fn ($v) => $v !== '');
    $pageUrl = fn ($p) => route($reportRoute, $listQuery + ['page' => $p]);
    // A tab keeps the search and month and starts again at page 1.
    $tabUrl = fn (string $type) => route($reportRoute, array_filter(['academic_status' => $type] + $listQuery, fn ($v) => $v !== ''));
    $typeLabel = \App\Domain\PersonnelDirectory::TYPE_TABS[$personnelType];
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
            {{-- The personnel type tab: the search and month stay inside it. --}}
            <input type="hidden" name="academic_status" value="{{ $personnelType }}">
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
                <div class="summary-stat-label">{{ $personnelType === '' ? 'Total Personnel' : $typeLabel }}</div>
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

        @include('partials.personnel-type-tabs')

        <div class="report-header-row">
            <h3><i class="fa-solid fa-list-check"></i> Personnel</h3>
            <span class="page-tag">Page {{ $page }} of {{ $totalPages }}</span>
        </div>

        {{-- Every person in the selected tab matching the current search (the
             same per-person DTR as Generate DTR), for the month beside the
             search. Their data is read from the server only when this is
             pressed. Kept inside this card so a live search replaces it
             together with the list (and the tabs). --}}
        <button class="generate-report-btn" data-url="{{ $allDtrUrl }}" data-print="{{ json_encode($allDtrPrint) }}"
            onclick="printAllDTRFromServer(this)" style="margin-top: 10px;">
            <i class="fa-solid fa-file-export"></i> Generate All DTR ({{ $typeLabel }})
        </button>

        <div class="teacher-list">
            @if (empty($teacherReports))
                <p class="empty-note">No {{ $personnelType === '' ? 'personnel' : $typeLabel }} found{{ $search !== '' ? ' for this search' : '' }}.</p>
            @endif
            @foreach ($teacherReports as $teacher)
                <div class="teacher-item">
                    <div class="teacher-header">
                        <strong>{{ $teacher['fullname'] }}</strong>

                        {{-- This card's month only (the month beside the search changes everyone's). --}}
                        <input type="month" class="monthPicker" value="{{ $monthValue }}" data-teacher-id="{{ $teacher['id'] }}"
                            aria-label="DTR month for {{ $teacher['fullname'] }}" onchange="changeCardMonth(this)">

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

        @include('partials.pagination', ['label' => 'Personnel pages'])
    </div>
</div>
@endsection

@push('scripts')
<script>
    /* A card's own month picker: loads that person's records for the month
       into their Generate DTR button. No other card and no other part of
       the page changes. If the month cannot be loaded, the picker goes back
       to the month the button still holds. */
    async function changeCardMonth(input) {
        const card = input.closest('.teacher-item');
        const button = card.querySelector('.dtr-btn');
        const current = JSON.parse(button.dataset.teacher);
        const previousMonth = String(current.monthStart || '').slice(0, 7);

        if (!/^\d{4}-\d{2}$/.test(input.value) || input.value === previousMonth) {
            input.value = previousMonth;
            return;
        }

        button.disabled = true;
        card.setAttribute('aria-busy', 'true');

        try {
            const url = appUrl('data/dtr-month') + '?teacher_id=' + encodeURIComponent(input.dataset.teacherId) + '&month=' + encodeURIComponent(input.value);
            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const result = response.ok ? await response.json() : null;

            if (!result || !result.success) {
                throw new Error(result ? result.message : 'HTTP ' + response.status);
            }

            button.dataset.teacher = JSON.stringify(result.teacher);
        } catch (error) {
            console.error('Could not load this month:', error);
            input.value = previousMonth;
            showMessageModal('Unable to load that month for this person. Please try again.', { title: 'Something Went Wrong', type: 'error' });
        } finally {
            button.disabled = false;
            card.removeAttribute('aria-busy');
        }
    }
</script>
<script src="{{ asset_v('js/dtr-view.js') }}"></script>
<script src="{{ asset_v('js/live-filters.js') }}"></script>
@endpush
