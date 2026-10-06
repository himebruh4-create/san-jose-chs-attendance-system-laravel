@extends('layouts.app')

@section('title', 'Principal Dashboard')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/principal-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/schedule-changes.css') }}">
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@section('content')
<div class="main-content">

    <div class="page-header-row">
        <h1>Principal Dashboard</h1>
        <span class="today-date-tag">{{ date('l, F j, Y', strtotime($today)) }}</span>
    </div>

    <p class="stats-note">
        Present, Late, Absent, and Not Yet Recorded reflect today only and reset daily.
        Total Teachers stays fixed, and On Leave only changes once a teacher's leave period ends.
    </p>

    <!-- ================= TODAY'S EVENT BANNER ================= -->

    @if ($todaysEvent)
        <div class="event-banner">
            📌 Today is {{ $todaysEvent['event_name'] }} ({{ $todaysEvent['event_type'] }})
        </div>
    @else
        <div class="event-banner none">
            ✓ No school events today — regular school day.
        </div>
    @endif

    <!-- ================= STAT CARDS (TODAY) ================= -->

    <div class="stats-wrapper">

        <div class="stat-card total">
            <span class="stat-num">{{ $totalTeachers }}</span>
            <span class="stat-label">Total Personnel</span>
        </div>

        <div class="stat-card present">
            <span class="stat-num">{{ $todayCounts['Present'] }}</span>
            <span class="stat-label">Present Today</span>
        </div>

        <div class="stat-card late">
            <span class="stat-num">{{ $todayCounts['Late'] }}</span>
            <span class="stat-label">Late Today</span>
        </div>

        <div class="stat-card absent">
            <span class="stat-num">{{ $todayCounts['Absent'] }}</span>
            <span class="stat-label">Absent Today</span>
        </div>

        <div class="stat-card leave">
            <span class="stat-num">{{ $todayCounts['On Leave'] }}</span>
            <span class="stat-label">On Leave Today</span>
        </div>

        <div class="stat-card pending">
            <span class="stat-num">{{ $todayCounts['Not Yet Recorded'] }}</span>
            <span class="stat-label">Not Yet Recorded</span>
        </div>

    </div>

    <div class="dashboard-card">
        <h2>Today's Breakdown</h2>
        <p class="subtitle">Present vs Late vs Absent vs On Leave</p>
        <div class="chart-container">
            <canvas id="todayChart"></canvas>
        </div>
    </div>

    <div class="dashboard-columns">

        <!-- LEFT COLUMN -->
        <div>

            <div class="dashboard-card">
                <h2>⚠️ Attendance Flags This Month</h2>
                <p class="subtitle">Personnel with 3 or more confirmed absences ({{ date('F Y') }})</p>

                @if (empty($flaggedTeachers))
                    <p class="empty-note">No personnel currently flagged. Attendance looks healthy this month.</p>
                @else
                    <table class="flagged-table">
                        <tr>
                            <th>Personnel</th>
                            <th>Department</th>
                            <th>Absences</th>
                        </tr>
                        @foreach ($flaggedTeachers as $flag)
                        <tr>
                            <td>{{ $flag['fullname'] }}</td>
                            <td>{{ $flag['department'] }}</td>
                            <td><span class="badge-count">{{ $flag['absent_count'] }}</span></td>
                        </tr>
                        @endforeach
                    </table>
                @endif
            </div>

            <div class="dashboard-card">
                <h2>Quick Access</h2>
                <div class="shortcut-grid">
                    <a href="{{ route('principal.monitoring') }}" class="shortcut-btn">Attendance Report</a>
                    <a href="{{ route('principal.personnel') }}" class="shortcut-btn">Personnel Management</a>
                    <a href="{{ route('principal.reports') }}" class="shortcut-btn">Reports</a>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div>

            <div class="dashboard-card">
                <h2>Personnel on Leave Today</h2>

                @forelse ($teachersOnLeaveToday as $leave)
                    <div class="leave-item">
                        <span>{{ $leave['fullname'] }}</span>
                        <span class="leave-tag">{{ $leave['detail'] }}</span>
                    </div>
                @empty
                    <p class="empty-note">No personnel are on leave today.</p>
                @endforelse
            </div>

            <div class="dashboard-card">
                <h2>School Schedule Changes</h2>
                <p class="subtitle">In effect today and the next 14 days — managed by Super Admin (view only)</p>
                @include('partials.schedule-changes')
            </div>

        </div>

    </div>

</div>
@endsection

@push('scripts')
@php
    $todayValues = [$todayCounts['Present'], $todayCounts['Late'], $todayCounts['Absent'], $todayCounts['On Leave']];
@endphp
<script>
new Chart(document.getElementById('todayChart'), {
    type: 'doughnut',
    data: {
        labels: ['Present', 'Late', 'Absent', 'On Leave'],
        datasets: [{
            data: @json($todayValues),
            backgroundColor: ['#4caf50', '#e65100', '#c62828', '#e8a317'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>
@endpush
