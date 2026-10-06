@extends('layouts.app')

@section('title', ['superadmin' => 'Reports', 'admin' => 'Reports - Admin', 'principal' => 'Reports - Principal'][$role])

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/reports.css') }}">
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@php
    $statusBoxes = ['Present' => 'present', 'Late' => 'late', 'Pending Review' => 'pending', 'Absent' => 'absent', 'On Leave' => 'leave'];
@endphp

@section('content')
<div class="main">

    <h1>Reports</h1>

    <div class="tabs">
        <button class="tab-btn {{ $activeTab === 'weekly' ? 'active' : '' }}" onclick="switchTab('weekly', this)">Weekly Report</button>
        <button class="tab-btn {{ $activeTab === 'monthly' ? 'active' : '' }}" onclick="switchTab('monthly', this)">Monthly Report</button>
    </div>

    <!-- ================= WEEKLY TAB ================= -->

    <div id="weeklyTab" class="tab-content {{ $activeTab === 'weekly' ? 'active' : '' }}">

        <div class="card">
            <div class="card-header-row">
                <h2>This Week ({{ date('M j', strtotime($weekly['start'])) }} - {{ date('M j, Y', strtotime($weekly['end'])) }})</h2>
                <button class="print-roster-btn" onclick="printWeeklyRoster()">
                    Print Weekly Roster
                </button>
            </div>
            <div class="chart-container">
                <canvas id="weeklyAnalyticsChart"></canvas>
            </div>
        </div>

        <div class="card">
            <h2>This Week's Status</h2>
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

    </div>

    <!-- ================= MONTHLY TAB ================= -->

    <div id="monthlyTab" class="tab-content {{ $activeTab === 'monthly' ? 'active' : '' }}">

        <div class="card">
            <form method="GET" class="month-picker-row">
                <label>Select Month:</label>
                <input type="month" name="monthPicker" value="{{ sprintf('%04d-%02d', $year, $month) }}" onchange="submitMonthPicker(this.value)">
                <input type="hidden" name="tab" value="monthly">
            </form>
        </div>

        <div class="card">
            @if ($role === 'principal')
                <div class="card-header-row">
                    <h2>{{ date('F Y', strtotime($monthStart)) }}</h2>
                    <button class="print-roster-btn" id="printMonthlySummaryBtn" onclick="printMonthlySummaryReport()">
                        Print Monthly Summary Report
                    </button>
                </div>
                <p class="empty-note" id="monthlyPrintError" style="display:none;"></p>
            @else
                <h2>{{ date('F Y', strtotime($monthStart)) }}</h2>
            @endif
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

    </div>

</div>
@endsection

@push('scripts')
@php
    $reportData = [
        'roster' => $weekly['roster'],
        'weekLabels' => $weekly['labels'],
        'weekDates' => $weekly['dates'],
        'weekRangeLabel' => date('M j', strtotime($weekly['start'])).' - '.date('M j, Y', strtotime($weekly['end'])),
        'weeklyChart' => $weekly['chart'],
        'monthlyTotals' => $monthly['totals'],
        'month' => sprintf('%02d', $month),
        'year' => (string) $year,
        'printedBy' => auth()->user()->email,
    ];
@endphp
<script>
window.REPORT = @json($reportData);
</script>
<script src="{{ asset_v('js/reports-analytics.js') }}"></script>
@endpush
