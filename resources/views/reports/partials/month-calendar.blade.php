{{-- Monthly calendar (Mon–Sat, and Sun when anyone works Sundays:
     AppDomainAttendanceWorkingDays). All Personnel: each day's status counts;
     one person ($person): that person's status. A past or current day
     opens the day's names and times (reports-analytics.js). Days and
     statuses are the ones the monthly lists count. Not printed, and never
     any scan photos. --}}
@php
    $calendarStatuses = ['Present' => 'present', 'Late' => 'late', 'Absent' => 'absent', 'On Leave' => 'leave', 'Pending Review' => 'pending'];
    $personLabel = fn (?string $status, array $day) => match ($status) {
        'School Event' => $day['label'] ?? 'School Event',
        'Adjusted' => 'Adjusted',
        'Not Scheduled' => $day['label'] ?? 'No work',
        'Not Yet Recorded' => 'Not yet recorded',
        null => $day['label'],
        default => null,
    };
    // The week's columns: Mon–Sat, plus Sun when anyone is scheduled on Sundays.
    $daysPerWeek = $calendar['daysPerWeek'] ?? 6;
    $weekdayHeaders = array_slice(['Mon' => 'M', 'Tue' => 'T', 'Wed' => 'W', 'Thu' => 'T', 'Fri' => 'F', 'Sat' => 'S', 'Sun' => 'S'], 0, $daysPerWeek);
    // 1 (Mon) to 7 (Sun) when today is in this month and its weekday is shown.
    $todaysWeekday = substr($today, 0, 7) === substr($monthStart, 0, 7) && (int) date('N', strtotime($today)) <= $daysPerWeek
        ? (int) date('N', strtotime($today))
        : null;
@endphp

<div class="month-calendar" aria-label="{{ $monthLabel }} calendar">
    <ul class="cal-legend" aria-label="Legend">
        @foreach ($calendarStatuses as $status => $class)
            <li><span class="cal-dot dot-{{ $class }}"></span>{{ $status }}</li>
        @endforeach
        <li class="cal-legend-off"><span class="cal-dot dot-off"></span>Holiday / no work</li>
        <li class="cal-legend-change"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i>Schedule change (not counted)</li>
        @if ($calendarHighlight ?? null)
            <li class="cal-legend-highlight"><span class="cal-dot dot-highlight"></span>Highlighted: {{ $calendarHighlight === 'holidays' ? 'holidays' : 'other schedule changes' }}</li>
        @endif
    </ul>

    <div class="cal-grid" style="--cal-days: {{ $daysPerWeek }};">
        {{-- One header bar in the table header style; today's weekday is
             marked when this is the current month. Phones show M T W T F S. --}}
        <div class="cal-weekdays">
            @foreach ($weekdayHeaders as $weekday => $initial)
                @php $isTodaysWeekday = $todaysWeekday === $loop->iteration; @endphp
                <div @class(['cal-weekday', 'is-today' => $isTodaysWeekday]) @if ($isTodaysWeekday) title="Today" @endif>
                    <span class="cal-weekday-full">{{ $weekday }}</span><span class="cal-weekday-short" aria-hidden="true">{{ $initial }}</span>
                </div>
            @endforeach
        </div>

        @foreach ($calendar['weeks'] as $week)
            @foreach ($week as $cellDate)
                @if ($cellDate === null)
                    <div class="cal-cell is-outside" aria-hidden="true"></div>
                    @continue
                @endif

                @php
                    $day = $calendar['days'][$cellDate];
                    $changes = $day['changes'] ?? [];
                    // ?highlight= from the dashboard: holidays, or the other changes.
                    $isHighlighted = match ($calendarHighlight ?? null) {
                        'holidays' => collect($changes)->contains('is_holiday', true),
                        'changes' => collect($changes)->contains('is_holiday', false),
                        default => false,
                    };
                    $cellClass = 'cal-cell'.($cellDate === $today ? ' is-today' : '').($day['future'] ? ' is-future' : '').($changes ? ' has-change' : '').($isHighlighted ? ' is-highlighted' : '');
                    $changeTitle = collect($changes)->map(fn ($change) => $change['name'].' ('.$change['type'].')')->implode('; ');
                @endphp

                {{-- A future day opens only when it has a schedule change (to read it). --}}
                @if ($day['future'] && ! $changes)
                    <div class="{{ $cellClass }}"><span class="cal-date">{{ (int) substr($cellDate, 8) }}</span>@if ($day['label'])<span class="cal-label" title="{{ $day['labelTitle'] ?? $day['label'] }}">{{ $day['label'] }}</span>@endif</div>
                @else
                    <button type="button" class="{{ $cellClass }}" data-date="{{ $cellDate }}" onclick="openCalendarDay(this.dataset.date)"
                        aria-label="{{ date('l, F j', strtotime($cellDate)) }}{{ $cellDate === $today ? ' (today)' : '' }}{{ $changes ? ', schedule change: '.$changeTitle : '' }}">
                        <span class="cal-date" @if ($cellDate === $today) aria-current="date" @endif>{{ (int) substr($cellDate, 8) }}</span>

                        {{-- Schedule change marker: its own label, apart from the status counts. --}}
                        @if ($changes)
                            <span class="cal-change" title="{{ $changeTitle }}">
                                <i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i><span class="cal-text">{{ $changes[0]['marker'] }}{{ count($changes) > 1 ? ' +'.(count($changes) - 1) : '' }}</span>
                            </span>
                        @endif

                        @if ($person)
                            @if (isset($calendarStatuses[$day['status'] ?? '']))
                                <span class="cal-status status-{{ $calendarStatuses[$day['status']] }}" title="{{ $day['status'] }}">
                                    <span class="cal-dot dot-{{ $calendarStatuses[$day['status']] }}"></span><span class="cal-text">{{ $day['status'] }}</span>
                                </span>
                            @elseif (! ($changes && ($day['status'] ?? null) === 'School Event') && ($label = $personLabel($day['status'] ?? null, $day)))
                                <span class="cal-label" title="{{ $day['labelTitle'] ?? $label }}">{{ $label }}</span>
                            @endif
                        @else
                            @if ($day['label'] && ! $changes)
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
