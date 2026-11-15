{{-- Report rows: one per person ($byPerson) or one per day. Each record is
     its own <tbody> so its photo strip (Super Admin only) prints on the
     same page as the record; each photo sits in its own scan's column.
     A record without any scan has no strip. The time columns are
     $row['times'] (App\Domain\Attendance\ReportTimes: the kiosk scan, else
     the attendance adjustment's time, in the DTR's columns); an adjusted
     time is marked "adj", on screen and on the printout. --}}
@php
    $showTime = fn ($t) => $t ? date('g:i A', strtotime($t)) : '—';
    $timeColumns = ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'];
    // Rows without times (none today) fall back to the raw scans.
    $cellOf = fn (array $row, string $column) => array_key_exists('times', $row)
        ? $row['times'][$column]
        : ($row[$column] ? ['time' => $row[$column], 'source' => 'scan'] : null);
    $statusClass = fn ($status) => match ($status) {
        'Present' => 'present',
        'Late' => 'late',
        'Absent' => 'absent',
        'On Leave' => 'leave',
        'Pending Review' => 'pending',
        'School Event' => 'event',
        'Adjusted' => 'adjusted',
        default => 'none',
    };
    $columns = ($byPerson ? 2 : 1) + 5;
@endphp

@if ($scrollable ?? false)
    {{-- Scrolls up and down when the rows do not fit; the header stays in view. --}}
    <div class="table-scroll scroll-panel sticky-table-wrapper" tabindex="0" aria-label="Attendance list">
@else
    <div class="table-scroll">
@endif
    <table class="report-table">
        <thead>
            <tr>
                @if ($byPerson)
                    <th class="th-left">Personnel</th>
                    <th>ID</th>
                @else
                    <th class="th-left">Date</th>
                @endif
                <th>AM Arrival</th>
                <th>AM Departure</th>
                <th>PM Arrival</th>
                <th>PM Departure</th>
                <th>Status</th>
            </tr>
        </thead>
        @forelse ($rows as $row)
            <tbody class="report-record">
                <tr>
                    @if ($byPerson)
                        <td class="td-left"><strong>{{ $row['fullname'] }}</strong></td>
                        <td>{{ $row['id_number'] }}</td>
                    @else
                        <td class="td-left"><strong>{{ date('D, M j, Y', strtotime($row['date'])) }}</strong></td>
                    @endif
                    @foreach ($timeColumns as $column)
                        @php $cell = $cellOf($row, $column); @endphp
                        <td>
                            {{ $showTime($cell['time'] ?? null) }}
                            @if (($cell['source'] ?? null) === \App\Domain\Attendance\ReportTimes::ADJUSTMENT)
                                <small class="time-source" title="From an attendance adjustment, not a kiosk scan">adj</small>
                            @endif
                        </td>
                    @endforeach
                    <td>
                        <span class="status-tag status-{{ $statusClass($row['status']) }}">{{ $row['status'] !== '' ? $row['status'] : '—' }}</span>
                        @if ($row['detail'] !== '' && $row['detail'] !== $row['status'])
                            <small class="status-detail">{{ $row['detail'] }}</small>
                        @endif
                    </td>
                </tr>
                @if ($showPhotos && array_filter(array_column($row['photos'], 'time')))
                    <tr class="photo-strip-row">
                        <td colspan="{{ $byPerson ? 2 : 1 }}"></td>
                        @include('reports.partials.photo-strip', [
                            'photos' => $row['photos'],
                            'caption' => $row['fullname'].' · '.date('M j, Y', strtotime($row['date'])),
                        ])
                        <td></td>
                    </tr>
                @endif
            </tbody>
        @empty
            <tbody>
                <tr><td colspan="{{ $columns }}" class="empty-note">{{ $emptyText }}</td></tr>
            </tbody>
        @endforelse
    </table>
</div>

@if (collect($rows)->contains(fn (array $row) => collect($timeColumns)->contains(fn (string $column) => ($cellOf($row, $column)['source'] ?? null) === \App\Domain\Attendance\ReportTimes::ADJUSTMENT)))
    <p class="help-note time-source-key"><small class="time-source">adj</small> <span>The time is from an attendance adjustment, not a kiosk scan.</span></p>
@endif
