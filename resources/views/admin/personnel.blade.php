@extends('layouts.app')

@section('title', 'Personnel List - Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/admin-personnel.css') }}">
@endpush

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">Admin Officer</div>
        <h1>Personnel List</h1>
        <p>Search and view personnel records and schedules managed by Super Admin.</p>
    </div>

    @include('partials.personnel-stats')

    <div class="card">

        <h3><i class="fa-solid fa-users"></i> Personnel List</h3>

        <!-- SEARCH BAR -->
        <form method="GET" action="{{ route('admin.personnel') }}" class="search-form" style="margin-top: 14px;">
            <input type="text" name="search" placeholder="Search teacher..." value="{{ $search }}">
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </form>

        <!-- TEACHER TABLE -->
        <div class="table-scroll sticky-table-wrapper">
        <table class="teacher-table">
        <thead>
        <tr>
            <th style="text-align:center;">No.</th>
            <th style="text-align:left;">ID Number</th>
            <th style="text-align:left;">Name</th>
            <th style="text-align:left;">Department</th>
            <th style="text-align:center;">Personnel Type</th>
            <th style="text-align:center;">Employment</th>
            <th style="text-align:left;">Schedule</th>
            <th style="text-align:center;">Barcode</th>
            <th style="text-align:center;">Photo</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($teacherList as $t)
        <tr>
            <td style="text-align:center;">{{ ($page - 1) * $limit + $loop->iteration }}</td>
            <td>{{ $t['id_number'] }}</td>
            <td><strong>{{ $t['fullname'] }}</strong></td>
            <td>{{ $t['department'] }}</td>
            <td style="text-align:center;">{{ \App\Domain\PersonnelDirectory::TYPE_LABELS[$t['academic_status']] ?? $t['academic_status'] }}</td>
            <td style="text-align:center;">{{ $t['employment_type'] }}</td>
            <td>
                @forelse ($t['schedules'] as $s)
                    <div class="schedule-line">
                        <strong>{{ $s['day'] }}:</strong>
                        {{ date('g:i A', strtotime($s['time_in'])) }} - {{ date('g:i A', strtotime($s['time_out'])) }}
                    </div>
                @empty
                    <span class="no-schedule">No schedule assigned</span>
                @endforelse
            </td>
            <td style="text-align:center;">
                <svg id="barcode-{{ (int) $t['id'] }}" class="barcode-svg" data-barcode="{{ $t['barcode'] }}"></svg>
            </td>
            <td style="text-align:center;" class="teacher-photo-cell">
                <img src="{{ \App\Domain\PersonnelDirectory::photoUrl($t['photo']) }}" alt="Personnel Photo">
            </td>
        </tr>
        @endforeach
        </tbody>
        </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination">
            @for ($i = 1; $i <= $totalPages; $i++)
                @if ($i == $page)
                    <span class="active">{{ $i }}</span>
                @else
                    <a href="{{ route('admin.personnel', ['page' => $i, 'search' => $search]) }}">{{ $i }}</a>
                @endif
            @endfor
        </div>

    </div>

</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/jsbarcode/JsBarcode.all.min.js') }}"></script>
<script>
    // Code 39, the same symbology as the printed ID badges the kiosk scans
    // (this page showed Code 128 in the native-PHP version).
    document.querySelectorAll('.barcode-svg').forEach(svg => {
        JsBarcode(svg, svg.dataset.barcode, {
            format: "CODE39",
            width: 1.2,
            height: 50,
            displayValue: true
        });
    });
</script>
@endpush
