@extends('layouts.app')

@section('title', ['superadmin' => 'Reports', 'admin' => 'Reports - Admin', 'principal' => 'Reports - Principal'][$role])

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/reports.css') }}">
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@php
    $statusBoxes = ['Present' => 'present', 'Late' => 'late', 'Pending Review' => 'pending', 'Absent' => 'absent', 'On Leave' => 'leave'];
    $filterData = compact('date', 'today', 'weekStart', 'previousWeek', 'nextWeek', 'month', 'year', 'person', 'personnelOptions');
    $dailyLabel = date('l, F j, Y', strtotime($date));
    $weekLabel = date('M j', strtotime($weekStart)).' – '.date('M j, Y', strtotime($weekEnd));
    $monthLabel = date('F Y', strtotime($monthStart));
@endphp

@section('content')
<div class="main">

    <h1>Reports</h1>

    <div class="tabs">
        <button class="tab-btn {{ $activeTab === 'daily' ? 'active' : '' }}" onclick="switchTab('daily', this)">Daily Report</button>
        <button class="tab-btn {{ $activeTab === 'weekly' ? 'active' : '' }}" onclick="switchTab('weekly', this)">Weekly Report</button>
        <button class="tab-btn {{ $activeTab === 'monthly' ? 'active' : '' }}" onclick="switchTab('monthly', this)">Monthly Report</button>
    </div>

    <!-- ================= DAILY TAB ================= -->

    <div id="dailyTab" class="tab-content {{ $activeTab === 'daily' ? 'active' : '' }}">

        <div class="card">
            @include('reports.partials.filter', ['tab' => 'daily'] + $filterData)
        </div>

        <div class="card">
            <div class="card-header-row">
                <h2>{{ $dailyLabel }}</h2>
                <div class="print-actions">
                    <button class="print-roster-btn" data-url="{{ $printUrls['daily'] }}" onclick="printServerReport(this)">
                        <i class="fa-solid fa-print"></i> Print Daily Attendance
                    </button>
                    @include('reports.partials.print-absences-button', ['url' => $absenceUrls['daily']])
                </div>
            </div>

            <div id="dailyPrintArea" class="report-print-area">
                @include('reports.partials.report-section', ['section' => 'daily', 'rows' => $daily, 'notYetRecorded' => $dailyNotYetRecorded])
            </div>
        </div>

    </div>

    <!-- ================= WEEKLY TAB ================= -->

    <div id="weeklyTab" class="tab-content {{ $activeTab === 'weekly' ? 'active' : '' }}">

        <div class="card">
            @include('reports.partials.filter', ['tab' => 'weekly'] + $filterData)
        </div>

        @if ($person)
            <div class="card">
                <div class="card-header-row">
                    <h2>{{ $weekLabel }}</h2>
                    <div class="print-actions">
                        <button class="print-roster-btn" data-url="{{ $printUrls['weekly'] }}" onclick="printServerReport(this)">
                            <i class="fa-solid fa-print"></i> Print Weekly Roster
                        </button>
                        @include('reports.partials.print-absences-button', ['url' => $absenceUrls['weekly']])
                    </div>
                </div>

                <div id="weeklyPrintArea" class="report-print-area">
                    @include('reports.partials.report-section', ['section' => 'weekly', 'rows' => $personWeek])
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-header-row">
                    <h2>{{ $weekLabel }}</h2>
                    <div class="print-actions">
                        <button class="print-roster-btn" onclick="printWeeklyRoster()">
                            <i class="fa-solid fa-print"></i> Print Weekly Roster
                        </button>
                        @include('reports.partials.print-absences-button', ['url' => $absenceUrls['weekly']])
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyAnalyticsChart"></canvas>
                </div>
            </div>

            <div class="card">
                <h2>Status for the Week</h2>
                <div class="status-lists">
                    @foreach ($statusBoxes as $status => $class)
                        <div class="status-box {{ $class }}">
                            <h3>{{ $status }} ({{ count($weekly['lists'][$status]) }})</h3>
                            @if (empty($weekly['lists'][$status]))
                                <p class="empty-note">No records.</p>
                            @else
                                <ul>
                                    @foreach ($weekly['lists'][$status] as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    <!-- ================= MONTHLY TAB ================= -->

    <div id="monthlyTab" class="tab-content {{ $activeTab === 'monthly' ? 'active' : '' }}">

        <div class="card">
            @include('reports.partials.filter', ['tab' => 'monthly'] + $filterData)
        </div>

        @if ($person)
            <div class="card">
                <div class="card-header-row">
                    <h2>{{ $monthLabel }}</h2>
                    <div class="print-actions">
                        <button class="print-roster-btn" data-url="{{ $printUrls['monthly'] }}" onclick="printServerReport(this)">
                            <i class="fa-solid fa-print"></i> Print Monthly Summary Report
                        </button>
                        @include('reports.partials.print-absences-button', ['url' => $absenceUrls['monthly']])
                    </div>
                </div>

                @include('reports.partials.month-calendar')

                <div id="monthlyPrintArea" class="report-print-area">
                    @include('reports.partials.report-section', ['section' => 'monthly', 'rows' => $personMonth, 'monthSummary' => $personMonthSummary])
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-header-row">
                    <h2>{{ $monthLabel }}</h2>
                    <div class="print-actions">
                        <button class="print-roster-btn" id="printMonthlySummaryBtn" onclick="printMonthlySummaryReport()">
                            <i class="fa-solid fa-print"></i> Print Monthly Summary Report
                        </button>
                        @include('reports.partials.print-absences-button', ['url' => $absenceUrls['monthly']])
                    </div>
                </div>
                <p class="empty-note" id="monthlyPrintError" style="display:none;"></p>
                @include('reports.partials.month-calendar')
            </div>

            <div class="card">
                <h2>This Month's Status</h2>
                <div class="status-lists">
                    @foreach ($statusBoxes as $status => $class)
                        @php $names = $monthly['lists'][$status]; @endphp
                        <div class="status-box {{ $class }}">
                            <h3>{{ $status }}</h3>
                            @if (empty($names))
                                <p class="empty-note">No records.</p>
                            @else
                                {{-- More than 5 names: the first 5, and a toggle for the rest --}}
                                <ul id="monthList-{{ $class }}" @class(['is-collapsed' => count($names) > 5])>
                                    @foreach ($names as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                                @if (count($names) > 5)
                                    <button type="button" class="list-toggle" aria-expanded="false" aria-controls="monthList-{{ $class }}"
                                        data-more="Show all {{ count($names) }}" onclick="toggleStatusList(this)">Show all {{ count($names) }}</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    @if ($showPhotos)
        <!-- Scan photo viewer (Super Admin only) -->
        <div class="modal-backdrop" id="scanPhotoModal" onclick="if (event.target === this) closeScanPhoto()">
            <div class="modal scan-photo-modal" role="dialog" aria-modal="true" aria-labelledby="scanPhotoModalTitle">
                <button type="button" class="modal-close" onclick="closeScanPhoto()" aria-label="Close">&times;</button>
                <h3 id="scanPhotoModalTitle"></h3>
                <img id="scanPhotoModalImage" src="" alt="">
            </div>
        </div>
    @endif

</div>
@endsection

@push('scripts')
@php
    $reportData = [
        'weekRangeLabel' => $weekLabel,
        'month' => sprintf('%02d', $month),
        'year' => (string) $year,
        'printedBy' => auth()->user()->roleLabel(),
        'weeklyRosterPrint' => $weeklyRosterPrint,
    ];

    if ($weekly) {
        $reportData += [
            'roster' => $weekly['roster'],
            'weekLabels' => $weekly['labels'],
            'weekDates' => $weekly['dates'],
            'weeklyChart' => $weekly['chart'],
        ];
    }

    $reportData['calendar'] = [
        'person' => (bool) $person,
        'days' => $calendar['days'],
    ];
@endphp
<script>
window.REPORT = @json($reportData);
</script>
<script src="{{ asset_v('js/live-filters.js') }}"></script>
<script src="{{ asset_v('js/reports-analytics.js') }}"></script>
@endpush
