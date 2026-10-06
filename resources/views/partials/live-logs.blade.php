{{-- Today's scans, one row per person (was admin/live-daily-logs.php). --}}
@php $showTime = fn ($t) => $t ? date('h:i A', strtotime($t)) : '—'; @endphp

<h3><i class="fa-solid fa-tower-broadcast"></i> Live Personnel Logs</h3>
<p style="margin: -6px 0 14px; color: #8a7d5c; font-size: 13px; font-weight: 600;">
    {{ date('l, F j, Y', strtotime($today)) }}
</p>

<table>
    <thead>
        <tr>
            <th>Personnel</th>
            <th>ID</th>
            <th>AM Arrival</th>
            <th>AM Departure</th>
            <th>PM Arrival</th>
            <th>PM Departure</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($liveLogs as $t)
        <tr>
            <td><strong>{{ $t['fullname'] }}</strong></td>
            <td>{{ $t['id_number'] }}</td>
            <td>{{ $showTime($t['am_arrival']) }}</td>
            <td>{{ $showTime($t['am_departure']) }}</td>
            <td>{{ $showTime($t['pm_arrival']) }}</td>
            <td>{{ $showTime($t['pm_departure']) }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="6" class="log-empty-state">
                <i class="fa-regular fa-clock"></i>&nbsp; No attendance recorded yet today.
            </td>
        </tr>
    @endforelse
    </tbody>
</table>
