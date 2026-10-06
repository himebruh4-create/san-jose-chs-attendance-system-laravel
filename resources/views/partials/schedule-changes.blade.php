{{-- $scheduleChanges: rows from App\Domain\Attendance\ScheduleChanges::forDisplay() --}}
@forelse ($scheduleChanges as $event)
    <div class="schedule-change">
        <div class="schedule-change-head">
            <strong>{{ $event['event_name'] }}</strong>
            <span class="event-date-tag{{ $event['in_effect'] ? ' event-in-effect' : '' }}">{{ $event['in_effect'] ? 'In effect' : 'Upcoming' }}</span>
        </div>

        <div class="schedule-change-meta">{{ $event['event_type'] }} &middot; {{ $event['date_label'] }} &middot; {{ $event['duration_label'] }}</div>

        <div class="schedule-change-meta">Included in Total Hours: {{ (int) $event['included_in_total_hours'] === 1 ? 'Yes' : 'No' }} &middot; Status: {{ $event['status'] }}</div>

        @if (! empty($event['remark']))
            <div class="schedule-change-meta">{{ $event['remark'] }}</div>
        @endif

        @if ($event['workers'])
            <details class="schedule-change-workers">
                <summary>{{ count($event['workers']) }} personnel scheduled to work</summary>
                <ul>
                    @foreach ($event['workers'] as $name)
                        <li>{{ $name }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@empty
    <p class="empty-note">No school schedule changes today or in the next 14 days.</p>
@endforelse
