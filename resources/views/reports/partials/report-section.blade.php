{{-- One tab's attendance section: Daily (All Personnel or one person), or
     one person's Weekly / Monthly. The Reports page shows it (with the Super
     Admin's photo strip when $showPhotos) and its printout
     (reports.attendance-print) prints the same section with $showPhotos
     false. $section is 'daily', 'weekly' or 'monthly'. --}}
@if ($person)
    @include('reports.partials.personnel-header')
@endif

@if ($section === 'daily' && $notYetRecorded > 0)
    <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ $notYetRecorded }} personnel not yet recorded today.</span></p>
@endif

@if ($section === 'monthly' && $monthSummary)
    <div class="summary-stats">
        <div class="summary-stat present"><span>On Time</span><strong>{{ (int) $monthSummary['on_time'] }}</strong></div>
        <div class="summary-stat late"><span>Late</span><strong>{{ (int) $monthSummary['late_count'] }}</strong></div>
        <div class="summary-stat absent"><span>Absent</span><strong>{{ (int) $monthSummary['absent_count'] }}</strong></div>
        <div class="summary-stat leave"><span>On Leave</span><strong>{{ (int) $monthSummary['on_leave_count'] }}</strong></div>
        <div class="summary-stat"><span>Working Days</span><strong>{{ (int) $monthSummary['total'] }}</strong></div>
        <div class="summary-stat"><span>Total Hours</span><strong>{{ $monthSummary['total_hours_pretty'] }}</strong></div>
    </div>
@endif

@include('reports.partials.attendance-table', [
    'rows' => $rows,
    'byPerson' => $section === 'daily' && ! $person,
    'emptyText' => match ($section) {
        'weekly' => 'No records for this week (the person was not yet registered).',
        'monthly' => 'No records for this month yet.',
        default => match (true) {
            $notYetRecorded > 0 => 'No attendance recorded yet today.',
            (bool) $person => 'No record for this date (the person was not yet registered).',
            default => 'No personnel were registered on this date.',
        },
    },
])
