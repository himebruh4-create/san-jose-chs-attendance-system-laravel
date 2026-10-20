{{-- Period + personnel filter of one Reports tab ($tab). It applies itself
     on change (live-filters.js). The other tabs' periods travel along as
     hidden fields, so they are kept. --}}
@php
    $periods = [
        'daily' => ['date', $date],
        'weekly' => ['week', $weekStart],
        'monthly' => ['monthPicker', sprintf('%04d-%02d', $year, $month)],
    ];
    $weekUrl = fn (string $day) => request()->fullUrlWithQuery(['tab' => 'weekly', 'week' => $day]);
@endphp

<form method="GET" class="report-filter" data-live-filter>
    <input type="hidden" name="tab" value="{{ $tab }}">
    @foreach ($periods as $otherTab => [$name, $value])
        @if ($otherTab !== $tab)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endif
    @endforeach

    <div class="filter-field">
        @if ($tab === 'daily')
            <label for="dailyDate">Date</label>
            <input type="date" id="dailyDate" name="date" value="{{ $date }}" max="{{ $today }}" required>
        @elseif ($tab === 'weekly')
            <label for="weeklyWeek">Any day in the week</label>
            <div class="week-picker">
                <a href="{{ $weekUrl($previousWeek) }}" class="week-step">&lsaquo; Previous week</a>
                <input type="date" id="weeklyWeek" name="week" value="{{ $weekStart }}" max="{{ $today }}" required>
                @if ($nextWeek)
                    <a href="{{ $weekUrl($nextWeek) }}" class="week-step">Next week &rsaquo;</a>
                @else
                    <span class="week-step disabled" aria-disabled="true">Next week &rsaquo;</span>
                @endif
            </div>
        @else
            <label for="monthlyMonth">Month</label>
            <input type="month" id="monthlyMonth" name="monthPicker" value="{{ $periods['monthly'][1] }}" required>
        @endif
    </div>

    <div class="filter-field filter-personnel">
        <label for="{{ $tab }}Personnel">Personnel</label>
        <select id="{{ $tab }}Personnel" name="teacher_id">
            <option value="">All Personnel</option>
            @foreach ($personnelOptions as $option)
                <option value="{{ $option->id }}" @selected($person?->id === $option->id)>{{ $option->fullname }} ({{ $option->id_number }})</option>
            @endforeach
        </select>
    </div>

    <noscript><button type="submit" class="apply-btn">Apply</button></noscript>
</form>
