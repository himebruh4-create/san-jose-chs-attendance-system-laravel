@extends('layouts.app')

@section('title', 'Dashboard - Super Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/superadmin-dashboard.css') }}">
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
@endpush

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">Administrative Aide III</div>
        <h1>Dashboard</h1>
        <p>Attendance overview for {{ date('l, F j, Y', strtotime($today)) }}.</p>
    </div>

    @if ($resetRequests->isNotEmpty())
        <div class="card password-reset-alert">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> Password Reset Request{{ $resetRequests->count() > 1 ? 's' : '' }}</h3>

            @foreach ($resetRequests as $req)
                <div class="reset-request-item">
                    <p><strong>{{ $req->full_name }}</strong> ({{ ucfirst($req->role) }}) has requested a password reset.</p>
                    <p class="reset-request-meta">Email: {{ $req->email }} &middot; {{ date('M j, Y g:i A', strtotime($req->requested_at)) }}</p>
                </div>
            @endforeach

            <p class="reset-request-hint">Go to Settings &rarr; Account Management to reset a password.</p>
        </div>
    @endif

    {{-- Pending Review reminder: hidden unless a new review period has begun
         with unresolved previous-period items. --}}
    <div class="card review-reminder-card" id="reviewReminderCard" style="display:none;">
        <h3><i class="fa-solid fa-clipboard-check"></i> Pending Review Reminder</h3>
        <p id="reviewReminderText"></p>
        <div class="review-reminder-actions">
            <a href="{{ route('superadmin.adjustments') }}">Review Now</a>
            <button type="button" onclick="dismissReviewReminder()">Dismiss</button>
        </div>
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

        <div class="card">
            <h3><i class="fa-solid fa-person-walking-arrow-right"></i> Personnel on Leave</h3>
            <p class="card-sub">Today</p>
            @forelse ($teachersOnLeaveToday as $leave)
                <div class="leave-item">
                    <span>{{ $leave['fullname'] }}</span>
                    <span class="leave-tag">{{ $leave['detail'] }}</span>
                </div>
            @empty
                <p class="empty-note">No personnel are on leave today.</p>
            @endforelse
        </div>

    </div>

    <div class="card live-logs-card live-logs-wrapper">
        @include('partials.live-logs')
    </div>

</div>
@endsection

@push('scripts')
@include('partials.dashboard-charts-script')
<script>
/* Pending Review reminder (dismissible per review period). Dismissing hides
   only this card — the sidebar count stays while items remain unresolved. */
(async function loadReviewReminder() {
    try {
        const response = await fetch(appUrl('data/review-summary'));
        const data = await response.json();

        if (!data.success) return;

        if (typeof window.setPendingReviewBadge === 'function') {
            window.setPendingReviewBadge(data.total);
        }

        if (!data.reminder_due) return;

        const n = data.previous;
        document.getElementById('reviewReminderText').textContent =
            'A new review period has started (' + data.current_period_label + '). ' +
            n + ' unresolved item' + (n === 1 ? ' remains' : 's remain') +
            ' from previous review periods.';
        document.getElementById('reviewReminderCard').style.display = 'block';
    } catch (e) {
        console.error('Review reminder failed:', e);
    }
})();

async function dismissReviewReminder() {
    document.getElementById('reviewReminderCard').style.display = 'none';
    try {
        await fetch(appUrl('data/review-reminder/dismiss'), { method: 'POST' });
    } catch (e) {
        console.error('Dismiss reminder failed:', e);
    }
}
</script>
@endpush
