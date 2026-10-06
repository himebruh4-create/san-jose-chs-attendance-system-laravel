@extends('layouts.app')

@section('title', 'Attendance Report - Principal')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/principal-monitoring.css') }}">
@endpush

@php
    $showTime = fn ($t) => $t ? date('h:i A', strtotime($t)) : '—';
    $statusClasses = [
        'Present' => 'status-present',
        'Late' => 'status-late',
        'Absent' => 'status-absent',
        'On Leave' => 'status-onleave',
        'Adjusted' => 'status-adjusted',
        'School Event' => 'status-schoolevent',
        'Pending Review' => 'status-pending',
    ];
@endphp

@section('content')
<div class="main">

    <h1>Attendance Report</h1>

    <div class="card">
        <form method="GET" action="{{ route('principal.monitoring') }}" class="filter-row">

            <div class="filter-group">
                <label>Personnel</label>
                <select name="teacher_id">
                    <option value="0">All Personnel</option>
                    @foreach ($allTeachers as $t)
                        <option value="{{ (int) $t['id'] }}" @selected($selectedTeacherId === (int) $t['id'])>{{ $t['fullname'] }} - {{ $t['id_number'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-group">
                <label>Date From</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}">
            </div>

            <div class="filter-group">
                <label>Date To</label>
                <input type="date" name="date_to" value="{{ $dateTo }}">
            </div>

            <button type="submit" class="apply-btn">Apply Filters</button>
            <button type="button" class="print-btn" onclick="printAttendanceMonitoring()">Print</button>

        </form>
    </div>

    <div class="card" id="attendanceResultsCard">

        @if ($rangeTooLarge)

            <div class="empty-state">
                <strong>Date range too wide for "All Personnel"</strong>
                <p>
                    Viewing all personnel is limited to 7 days at a time to keep the page fast and readable.
                    Please narrow your date range to 7 days or fewer, or select a specific person to view a longer period.
                </p>
            </div>

        @else

            <p class="results-count">
                Showing {{ count($rows) }} record{{ count($rows) !== 1 ? 's' : '' }}
                from {{ date('M j, Y', strtotime($dateFrom)) }} to {{ date('M j, Y', strtotime($dateTo)) }}
            </p>

            @if (empty($rows))

                <div class="empty-state">
                    <strong>No attendance records found</strong>
                    <p>Try adjusting the personnel or date range filters above.</p>
                </div>

            @else

                <div class="table-scroll-wrapper" id="attendanceScrollWrapper">
                    <table id="attendanceMonitoringTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                @if ($selectedTeacherId === 0)
                                    <th>Personnel</th>
                                    <th>Department</th>
                                @endif
                                <th>AM Arrival</th>
                                <th>AM Departure</th>
                                <th>PM Arrival</th>
                                <th>PM Departure</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ date('M j, Y (D)', strtotime($row['date'])) }}</td>

                                    @if ($selectedTeacherId === 0)
                                        <td>{{ $row['fullname'] }}</td>
                                        <td>{{ $row['department'] }}</td>
                                    @endif

                                    <td>{{ $showTime($row['am_arrival']) }}</td>
                                    <td>{{ $showTime($row['am_departure']) }}</td>
                                    <td>{{ $showTime($row['pm_arrival']) }}</td>
                                    <td>{{ $showTime($row['pm_departure']) }}</td>

                                    <td>
                                        <span class="status-badge {{ $statusClasses[$row['status']] ?? '' }}">{{ $row['status'] }}</span>
                                        @if ($row['detail'] && ! in_array($row['status'], ['Present', 'Late', 'Absent'], true))
                                            <br><small style="color:#777;">{{ $row['detail'] }}</small>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="scroll-controls">
                    <button type="button" class="scroll-btn" onclick="scrollAttendanceTable(-1)" title="Scroll up">
                        <i class="fa-solid fa-chevron-up"></i>
                    </button>
                    <button type="button" class="scroll-btn" onclick="scrollAttendanceTable(1)" title="Scroll down">
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                </div>

            @endif

        @endif

    </div>

</div>
@endsection

@push('scripts')
@php
    $monitoringData = [
        'printedBy' => auth()->user()->email,
        'dateFromLabel' => date('M j, Y', strtotime($dateFrom)),
        'dateToLabel' => date('M j, Y', strtotime($dateTo)),
        'selectedTeacherName' => $selectedTeacherName,
    ];
@endphp
<script>
window.MONITORING = @json($monitoringData);
</script>
<script src="{{ asset_v('js/principal-monitoring.js') }}"></script>
@endpush
