@extends('layouts.app')

@section('title', 'Dashboard - Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/admin-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/schedule-changes.css') }}">
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">Admin Officer</div>
        <h1>Administrative Officer IV Dashboard</h1>
        <p>Attendance overview and live monitoring for {{ date('l, F j, Y', strtotime($today)) }}.</p>
    </div>

    <div class="stats-wrapper">

        <div class="stat-headline">
            <span class="stat-label">Total Personnel</span>
            <span class="stat-number">{{ $totalPersonnel }}</span>
        </div>

        <div class="stat-group">
            <span class="stat-group-label">Employment Type</span>
            <div class="stat-group-boxes">
                @foreach (['Regular', 'Part-Time', 'Contractual'] as $type)
                    <div class="stat-box-mini">
                        <span class="stat-mini-number">{{ $employmentTypeCounts[$type] }}</span>
                        <span class="stat-mini-label">{{ $type }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="stat-group">
            <span class="stat-group-label">Today's Attendance</span>
            <div class="stat-group-boxes">
                <div class="stat-box-mini present">
                    <span class="stat-mini-number">{{ $todayCounts['Present'] }}</span>
                    <span class="stat-mini-label">Present</span>
                </div>
                <div class="stat-box-mini late">
                    <span class="stat-mini-number">{{ $todayCounts['Late'] }}</span>
                    <span class="stat-mini-label">Late</span>
                </div>
                <div class="stat-box-mini absent">
                    <span class="stat-mini-number">{{ $todayCounts['Absent'] }}</span>
                    <span class="stat-mini-label">Absent</span>
                </div>
                <div class="stat-box-mini leave">
                    <span class="stat-mini-number">{{ $todayCounts['On Leave'] }}</span>
                    <span class="stat-mini-label">On Leave</span>
                </div>
                <div class="stat-box-mini pending">
                    <span class="stat-mini-number">{{ $todayCounts['Not Yet Recorded'] }}</span>
                    <span class="stat-mini-label">Not Yet Recorded</span>
                </div>
            </div>
        </div>

    </div>

    <div class="charts-row">

        <div class="card">
            <h3><i class="fa-solid fa-chart-pie"></i> Today's Breakdown</h3>
            <p class="card-sub">Present vs Late vs Absent vs On Leave</p>
            <div class="chart-container">
                <canvas id="todayChart"></canvas>
            </div>
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-chart-column"></i> Weekly Attendance Trend</h3>
            <p class="card-sub">{{ date('M j', strtotime($week['start'])) }} - {{ date('M j, Y', strtotime($week['end'])) }}</p>
            <div class="chart-container">
                <canvas id="weeklyChart"></canvas>
            </div>
        </div>

    </div>

    <div class="charts-row">

        <div class="card">
            <h3><i class="fa-solid fa-person-walking-arrow-right"></i> Personnel on Leave</h3>
            <p class="card-sub">Today — managed by Super Admin</p>
            @forelse ($teachersOnLeaveToday as $leave)
                <div class="leave-item">
                    <span>{{ $leave['fullname'] }}</span>
                    <span class="leave-tag">{{ $leave['detail'] }}</span>
                </div>
            @empty
                <p class="empty-note">No personnel are on leave today.</p>
            @endforelse
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-calendar-days"></i> School Schedule Changes</h3>
            <p class="card-sub">In effect today and the next 14 days — managed by Super Admin (view only)</p>
            @include('partials.schedule-changes')
        </div>

    </div>

    <div class="live-logs-card live-logs-wrapper">

        <div id="printArea" class="logs-content">
            @include('partials.live-logs')
        </div>

        <div class="print-btn-container">
            <button onclick="printAttendance()" class="print-btn">
                <i class="fa-solid fa-print"></i> Print Daily Attendance
            </button>
        </div>

    </div>

</div>
@endsection

@push('scripts')
@include('partials.dashboard-charts-script')
<script>
const printedBy = @json(auth()->user()->email);
</script>
<script src="{{ asset_v('js/print-daily-attendance.js') }}"></script>
@endpush
