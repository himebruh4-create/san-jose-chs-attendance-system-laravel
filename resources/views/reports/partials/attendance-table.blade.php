{{-- Report rows: one per person ($byPerson) or one per day. Each record is
     its own <tbody> so its photo strip (Super Admin only) prints on the
     same page as the record; each photo sits in its own scan's column.
     A record without any scan has no strip. --}}
@php
    $showTime = fn ($t) => $t ? date('g:i A', strtotime($t)) : '—';
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

<div class="table-scroll">
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
                    <td>{{ $showTime($row['am_arrival']) }}</td>
                    <td>{{ $showTime($row['am_departure']) }}</td>
                    <td>{{ $showTime($row['pm_arrival']) }}</td>
                    <td>{{ $showTime($row['pm_departure']) }}</td>
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
