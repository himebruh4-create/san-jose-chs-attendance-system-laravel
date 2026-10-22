{{-- Monthly calendar (Mon–Sat). All Personnel: each day's status counts;
     one person ($person): that person's status. A past or current day
     opens the day's names and times (reports-analytics.js). Days and
     statuses are the ones the monthly lists count. Not printed, and never
     any scan photos. --}}
@php
    $calendarStatuses = ['Present' => 'present', 'Late' => 'late', 'Absent' => 'absent', 'On Leave' => 'leave', 'Pending Review' => 'pending'];
    $personLabel = fn (?string $status, array $day) => match ($status) {
        'School Event' => $day['label'] ?? 'School Event',
        'Adjusted' => 'Adjusted',
        'Not Scheduled' => 'Not scheduled',
        'Not Yet Recorded' => 'Not yet recorded',
        null => $day['label'],
        default => null,
    };
@endphp

<div class="month-calendar" aria-label="{{ $monthLabel }} calendar">
    <ul class="cal-legend" aria-label="Legend">
        @foreach ($calendarStatuses as $status => $class)
            <li><span class="cal-dot dot-{{ $class }}"></span>{{ $status }}</li>
        @endforeach
        <li class="cal-legend-off"><span class="cal-dot dot-off"></span>Holiday / no work</li>
    </ul>

    <div class="cal-grid">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
            <div class="cal-weekday">{{ $weekday }}</div>
        @endforeach

        @foreach ($calendar['weeks'] as $week)
            @foreach ($week as $cellDate)
                @if ($cellDate === null)
                    <div class="cal-cell is-outside" aria-hidden="true"></div>
                    @continue
                @endif

                @php
                    $day = $calendar['days'][$cellDate];
                    $cellClass = 'cal-cell'.($cellDate === $today ? ' is-today' : '').($day['future'] ? ' is-future' : '');
                @endphp

                @if ($day['future'])
                    <div class="{{ $cellClass }}"><span class="cal-date">{{ (int) substr($cellDate, 8) }}</span></div>
                @else
                    <button type="button" class="{{ $cellClass }}" data-date="{{ $cellDate }}" onclick="openCalendarDay(this.dataset.date)"
                        aria-label="{{ date('l, F j', strtotime($cellDate)) }}{{ $cellDate === $today ? ' (today)' : '' }}">
                        <span class="cal-date" @if ($cellDate === $today) aria-current="date" @endif>{{ (int) substr($cellDate, 8) }}</span>

                        @if ($person)
                            @if (isset($calendarStatuses[$day['status'] ?? '']))
                                <span class="cal-status status-{{ $calendarStatuses[$day['status']] }}" title="{{ $day['status'] }}">
                                    <span class="cal-dot dot-{{ $calendarStatuses[$day['status']] }}"></span><span class="cal-text">{{ $day['status'] }}</span>
                                </span>
                            @elseif ($label = $personLabel($day['status'], $day))
                                <span class="cal-label" title="{{ $day['labelTitle'] ?? $label }}">{{ $label }}</span>
                            @endif
                        @else
                            @if ($day['label'])
                                <span class="cal-label" title="{{ $day['labelTitle'] ?? $day['label'] }}">{{ $day['label'] }}</span>
                            @endif
                            @if ($day['counts'])
                                <span class="cal-counts">
                                    @foreach ($calendarStatuses as $status => $class)
                                        @if (! empty($day['counts'][$status]))
                                            <span class="cal-count status-{{ $class }}" title="{{ $status }}: {{ $day['counts'][$status] }}">
                                                <span class="cal-dot dot-{{ $class }}"></span><span class="cal-text">{{ $day['counts'][$status] }}</span>
                                            </span>
                                        @endif
                                    @endforeach
                                </span>
                            @endif
                        @endif
                    </button>
                @endif
            @endforeach
        @endforeach
    </div>
</div>

<div class="modal-backdrop" id="calendarDayModal" onclick="if (event.target === this) closeCalendarDay()">
    <div class="modal calendar-day-modal" role="dialog" aria-modal="true" aria-labelledby="calendarDayTitle">
        <button type="button" class="modal-close" onclick="closeCalendarDay()" aria-label="Close">&times;</button>
        <h3 id="calendarDayTitle"></h3>
        <a href="#" id="calendarDayDailyLink" class="apply-btn calendar-daily-link">
            <i class="fa-solid fa-calendar-day" aria-hidden="true"></i> Open in Daily report
        </a>
        <div id="calendarDayBody"></div>
    </div>
</div>
