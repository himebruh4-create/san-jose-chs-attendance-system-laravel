@extends('layouts.app')

@section('title', 'Personnel Management - Principal')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/principal-personnel.css') }}">
<script src="{{ asset('vendor/jsbarcode/JsBarcode.all.min.js') }}"></script>
@endpush

@section('content')
<div class="main">

    <h1>Personnel List</h1>

    @include('partials.personnel-stats')

    <div class="card">

        <form method="GET" action="{{ route('principal.personnel') }}" class="search-form">
            <input type="text" name="search" placeholder="Search by name or ID number" value="{{ $search }}">
            <button type="submit">Search</button>
            @if ($search !== '')
                <a href="{{ route('principal.personnel') }}" class="clear-link">Clear</a>
            @endif
        </form>

        @if (empty($teachers))

            <div class="empty-state">
                <strong>No teachers found</strong>
                <p>Try a different search term.</p>
            </div>

        @else

            <div class="table-scroll-wrapper" id="teacherScrollWrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Photo</th>
                            <th>ID Number</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Personnel Type</th>
                            <th>Employment</th>
                            <th>Schedule</th>
                            <th>Barcode</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teachers as $teacher)
                            @php
                                $academic = strtolower((string) $teacher['academic_status']) === 'academic';
                                $employmentClass = match (strtolower((string) $teacher['employment_type'])) {
                                    'regular' => 'full-time',
                                    'contractual' => 'contractual',
                                    default => 'part-time',
                                };
                            @endphp
                            <tr>
                                <td>{{ ($page - 1) * $limit + $loop->iteration }}</td>
                                <td>
                                    <img src="{{ \App\Domain\PersonnelDirectory::photoUrl($teacher['photo']) }}" alt="Photo" class="teacher-photo">
                                </td>
                                <td>{{ $teacher['id_number'] }}</td>
                                <td>{{ $teacher['fullname'] }}</td>
                                <td>{{ $teacher['department'] }}</td>
                                <td>
                                    <span class="pill {{ $academic ? 'academic' : 'non-academic' }}">
                                        {{ $academic ? 'Teaching Staff' : 'Non-Teaching Staff / School Administrator' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="pill {{ $employmentClass }}">{{ $teacher['employment_type'] }}</span>
                                </td>
                                <td>
                                    @forelse ($teacher['schedules'] as $s)
                                        <div class="schedule-line">
                                            <strong>{{ $s['day'] }}:</strong>
                                            {{ date('g:i A', strtotime($s['time_in'])) }} - {{ date('g:i A', strtotime($s['time_out'])) }}
                                        </div>
                                    @empty
                                        <span class="no-schedule">No schedule assigned</span>
                                    @endforelse
                                </td>
                                <td>
                                    <svg class="barcode-svg" data-barcode="{{ $teacher['barcode'] }}"></svg>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="scroll-controls">
                <button type="button" class="scroll-btn" onclick="scrollTeacherTable(-1)" title="Scroll up">
                    <i class="fa-solid fa-chevron-up"></i>
                </button>
                <button type="button" class="scroll-btn" onclick="scrollTeacherTable(1)" title="Scroll down">
                    <i class="fa-solid fa-chevron-down"></i>
                </button>
            </div>

            @if ($totalPages > 1)
                <div class="pagination">
                    @for ($i = 1; $i <= $totalPages; $i++)
                        @if ($i == $page)
                            <span class="active">{{ $i }}</span>
                        @else
                            <a href="{{ route('principal.personnel', ['page' => $i, 'search' => $search]) }}">{{ $i }}</a>
                        @endif
                    @endfor
                </div>
            @endif

        @endif

    </div>

</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.barcode-svg').forEach(svg => {
    JsBarcode(svg, svg.dataset.barcode, {
        format: 'CODE39',
        displayValue: true,
        fontSize: 10,
        height: 30,
        width: 1
    });
});

function scrollTeacherTable(direction) {
    const wrapper = document.getElementById('teacherScrollWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({ top: direction * 220, behavior: 'smooth' });
}
</script>
@endpush
