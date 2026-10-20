{{-- Period + personnel filter of one Reports tab ($tab). The other tabs'
     periods travel along as hidden fields, so they are kept on Apply. --}}
@php
    $periods = [
        'daily' => ['date', $date],
        'weekly' => ['week', date('o-\WW', strtotime($weekStart))],
        'monthly' => ['monthPicker', sprintf('%04d-%02d', $year, $month)],
    ];
@endphp

<form method="GET" class="report-filter">
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
            <label for="weeklyWeek">Week</label>
            <input type="week" id="weeklyWeek" name="week" value="{{ $periods['weekly'][1] }}" max="{{ date('o-\WW', strtotime($today)) }}" required>
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

    <button type="submit" class="apply-btn"><i class="fa-solid fa-check"></i> Apply</button>
</form>
