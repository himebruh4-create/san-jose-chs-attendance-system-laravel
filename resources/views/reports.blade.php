@extends('layouts.app')

@section('title', ['superadmin' => 'Reports', 'admin' => 'Reports - Admin', 'principal' => 'Reports - Principal'][$role])

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/reports.css') }}">
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@php
    $statusBoxes = ['Present' => 'present', 'Late' => 'late', 'Pending Review' => 'pending', 'Absent' => 'absent', 'On Leave' => 'leave'];
    $filterData = compact('date', 'today', 'weekStart', 'month', 'year', 'person', 'personnelOptions');
    $dailyLabel = date('l, F j, Y', strtotime($date));
    $weekLabel = date('M j', strtotime($weekStart)).' - '.date('M j, Y', strtotime($weekEnd));
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
                <button class="print-roster-btn" onclick="printReportArea('dailyPrintArea', 'Daily Attendance Report', {{ Js::from($dailyLabel) }})">
                    <i class="fa-solid fa-print"></i> Print Daily Attendance
                </button>
            </div>

            <div id="dailyPrintArea" class="report-print-area">
                @if ($person)
                    @include('reports.partials.personnel-header')
                @endif
                @include('reports.partials.attendance-table', [
                    'rows' => $daily,
                    'byPerson' => ! $person,
                    'emptyText' => $person ? 'No record for this date (the person was not yet registered).' : 'No personnel were registered on this date.',
                ])
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
                    <h2>Week of {{ $weekLabel }}</h2>
                    <button class="print-roster-btn" onclick="printReportArea('weeklyPrintArea', 'Weekly Attendance Roster', {{ Js::from($weekLabel) }})">
                        <i class="fa-solid fa-print"></i> Print Weekly Roster
                    </button>
                </div>

                <div id="weeklyPrintArea" class="report-print-area">
                    @include('reports.partials.personnel-header')
                    @include('reports.partials.attendance-table', [
                        'rows' => $personWeek,
                        'byPerson' => false,
                        'emptyText' => 'No records for this week (the person was not yet registered).',
                    ])
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-header-row">
                    <h2>Week of {{ $weekLabel }}</h2>
                    <button class="print-roster-btn" onclick="printWeeklyRoster()">
                        <i class="fa-solid fa-print"></i> Print Weekly Roster
                    </button>
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
                    <button class="print-roster-btn" onclick="printReportArea('monthlyPrintArea', 'Monthly Attendance Summary Report', {{ Js::from($monthLabel) }})">
                        <i class="fa-solid fa-print"></i> Print Monthly Summary Report
                    </button>
                </div>

                <div id="monthlyPrintArea" class="report-print-area">
                    @include('reports.partials.personnel-header')

                    @if ($personMonthSummary)
                        <div class="summary-stats">
                            <div class="summary-stat present"><span>On Time</span><strong>{{ (int) $personMonthSummary['on_time'] }}</strong></div>
                            <div class="summary-stat late"><span>Late</span><strong>{{ (int) $personMonthSummary['late_count'] }}</strong></div>
                            <div class="summary-stat absent"><span>Absent</span><strong>{{ (int) $personMonthSummary['absent_count'] }}</strong></div>
                            <div class="summary-stat leave"><span>On Leave</span><strong>{{ (int) $personMonthSummary['on_leave_count'] }}</strong></div>
                            <div class="summary-stat"><span>Working Days</span><strong>{{ (int) $personMonthSummary['total'] }}</strong></div>
                            <div class="summary-stat"><span>Total Hours</span><strong>{{ $personMonthSummary['total_hours_pretty'] }}</strong></div>
                        </div>
                    @endif

                    @include('reports.partials.attendance-table', [
                        'rows' => $personMonth,
                        'byPerson' => false,
                        'emptyText' => 'No records for this month yet.',
                    ])
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-header-row">
                    <h2>{{ $monthLabel }}</h2>
                    <button class="print-roster-btn" id="printMonthlySummaryBtn" onclick="printMonthlySummaryReport()">
                        <i class="fa-solid fa-print"></i> Print Monthly Summary Report
                    </button>
                </div>
                <p class="empty-note" id="monthlyPrintError" style="display:none;"></p>
                <div class="chart-container">
                    <canvas id="monthlyAnalyticsChart"></canvas>
                </div>
            </div>

            <div class="card">
                <h2>This Month's Status</h2>
                <div class="status-lists">
                    @foreach ($statusBoxes as $status => $class)
                        <div class="status-box {{ $class }}">
                            <h3>{{ $status }}</h3>
                            @if (empty($monthly['lists'][$status]))
                                <p class="empty-note">No records.</p>
                            @else
                                <ul>
                                    @foreach ($monthly['lists'][$status] as $item)
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
        'printedBy' => auth()->user()->email,
    ];

    if ($weekly) {
        $reportData += [
            'roster' => $weekly['roster'],
            'weekLabels' => $weekly['labels'],
            'weekDates' => $weekly['dates'],
            'weeklyChart' => $weekly['chart'],
        ];
    }

    if ($monthly) {
        $reportData['monthlyTotals'] = $monthly['totals'];
    }
@endphp
<script>
window.REPORT = @json($reportData);
</script>
<script src="{{ asset_v('js/reports-analytics.js') }}"></script>
@endpush
