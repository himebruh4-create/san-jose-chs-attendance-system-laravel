@extends('layouts.app')

@section('title', 'Scan Photos')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/superadmin-scan-photos.css') }}">
@endpush

@section('content')
<main class="main-content">

    <div class="scan-photos-header">
        <div>
            <h1>Scan Photos</h1>
            <p>Webcam pictures the kiosk takes at every scan, next to each person's registered photo. Kept for {{ $retentionDays }} days.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('superadmin.scan-photos') }}" class="scan-photos-filters">
        <div class="day-picker">
            <a href="{{ route('superadmin.scan-photos', ['date' => $date->copy()->subDay()->toDateString(), 'teacher_id' => $teacherId, 'outcome' => $outcome]) }}"
               class="day-step" title="Previous day"><i class="fa-solid fa-chevron-left"></i></a>
            <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}" onchange="this.form.submit()">
            <a href="{{ route('superadmin.scan-photos', ['date' => $date->copy()->addDay()->toDateString(), 'teacher_id' => $teacherId, 'outcome' => $outcome]) }}"
               class="day-step {{ $date->gte(today()) ? 'disabled' : '' }}" title="Next day"><i class="fa-solid fa-chevron-right"></i></a>
        </div>

        <select name="teacher_id" onchange="this.form.submit()">
            <option value="">Everyone</option>
            @foreach ($teachers as $teacher)
                <option value="{{ $teacher->id }}" @selected((string) $teacherId === (string) $teacher->id)>
                    {{ $teacher->fullname }}{{ $teacher->is_deleted ? ' (archived)' : '' }}
                </option>
            @endforeach
        </select>

        <select name="outcome" onchange="this.form.submit()">
            @foreach (\App\Http\Controllers\SuperAdmin\ScanPhotosController::OUTCOME_FILTERS as $value => $label)
                <option value="{{ $value }}" @selected($outcome === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <noscript><button type="submit" class="filter-btn">Show</button></noscript>
    </form>

    @if ($errors->any())
        <div class="scan-photos-error">{{ $errors->first() }}</div>
    @endif

    <div class="scan-photos-summary">
        <div><strong>{{ $summary['total'] }}</strong><span>scans on {{ $date->format('M j, Y') }}</span></div>
        <div><strong>{{ $summary['withPhoto'] }}</strong><span>with a photo</span></div>
        <div class="{{ $summary['notAccepted'] ? 'warn' : '' }}"><strong>{{ $summary['notAccepted'] }}</strong><span>not accepted</span></div>
    </div>

    @if ($photos->isEmpty())
        <div class="scan-photos-empty">
            <i class="fa-regular fa-images"></i>
            <strong>No scan photos</strong>
            <span>Nothing matches these filters for {{ $date->format('F j, Y') }}.</span>
        </div>
    @else
        <div class="scan-photo-grid">
            @foreach ($photos as $photo)
                <figure class="scan-card {{ $photo['outcome'] === 'accepted' ? '' : 'not-accepted' }}">
                    <div class="scan-card-images">
                        @if ($photo['url'])
                            <button type="button" class="scan-card-photo" title="Enlarge"
                                    data-photo="{{ $photo['url'] }}" data-registered="{{ $photo['registered_photo'] }}"
                                    data-caption="{{ $photo['name'] }} · {{ $photo['time'] }}">
                                <img src="{{ $photo['url'] }}" alt="Scan photo of {{ $photo['name'] }}" loading="lazy">
                            </button>
                        @else
                            <div class="scan-card-photo missing"><i class="fa-solid fa-video-slash"></i><span>No photo</span></div>
                        @endif

                        <div class="scan-card-registered" title="Registered photo">
                            @if ($photo['registered_photo'])
                                <img src="{{ $photo['registered_photo'] }}" alt="Registered photo" loading="lazy">
                            @else
                                <i class="fa-solid fa-user"></i>
                            @endif
                        </div>
                    </div>

                    <figcaption>
                        <strong>{{ $photo['name'] }}</strong>
                        <span class="scan-card-meta">
                            {{ $photo['time'] }}@if ($photo['kind']) · {{ $photo['kind'] }}@endif
                        </span>
                        <span class="scan-card-meta">
                            <span class="outcome-badge outcome-{{ $photo['outcome'] }}">{{ $photo['outcome_label'] }}</span>
                            @if ($photo['rapid_scan'])<span class="flag-chip flag-rapid" title="Another person's accepted scan on this kiosk was within {{ \App\Domain\Attendance\ScanVerificationCounts::RAPID_SECONDS }} seconds"><i class="fa-solid fa-bolt"></i> Rapid scan</span>@endif
                            @if ($photo['manual_entry'])<span class="flag-chip flag-manual" title="Barcode was typed, not scanned"><i class="fa-solid fa-keyboard"></i> Manual entry</span>@endif
                            @if ($photo['kiosk'])<span class="kiosk-name" title="Kiosk"><i class="fa-solid fa-desktop"></i> {{ $photo['kiosk'] }}</span>@endif
                        </span>
                    </figcaption>
                </figure>
            @endforeach
        </div>

        @if ($photos->hasPages())
            <nav class="scan-photos-pages">
                @if ($photos->onFirstPage())
                    <span class="page-btn disabled"><i class="fa-solid fa-chevron-left"></i> Newer</span>
                @else
                    <a class="page-btn" href="{{ $photos->previousPageUrl() }}"><i class="fa-solid fa-chevron-left"></i> Newer</a>
                @endif
                <span>Page {{ $photos->currentPage() }} of {{ $photos->lastPage() }}</span>
                @if ($photos->hasMorePages())
                    <a class="page-btn" href="{{ $photos->nextPageUrl() }}">Older <i class="fa-solid fa-chevron-right"></i></a>
                @else
                    <span class="page-btn disabled">Older <i class="fa-solid fa-chevron-right"></i></span>
                @endif
            </nav>
        @endif
    @endif

</main>

<dialog id="scanPhotoViewer" class="scan-photo-viewer">
    <div class="viewer-images">
        <figure><img id="viewerPhoto" alt="Scan photo"><figcaption>Kiosk photo</figcaption></figure>
        <figure id="viewerRegisteredWrap"><img id="viewerRegistered" alt="Registered photo"><figcaption>Registered photo</figcaption></figure>
    </div>
    <p id="viewerCaption"></p>
    <form method="dialog"><button class="viewer-close" type="submit"><i class="fa-solid fa-xmark"></i> Close</button></form>
</dialog>
@endsection

@push('scripts')
<script>
(function () {
    const viewer = document.getElementById('scanPhotoViewer');

    document.querySelectorAll('.scan-card-photo[data-photo]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('viewerPhoto').src = button.dataset.photo;
            const registered = button.dataset.registered;
            document.getElementById('viewerRegisteredWrap').style.display = registered ? '' : 'none';
            document.getElementById('viewerRegistered').src = registered || '';
            document.getElementById('viewerCaption').textContent = button.dataset.caption;
            viewer.showModal();
        });
    });

    // Click outside the picture closes it.
    viewer.addEventListener('click', function (event) {
        if (event.target === viewer) { viewer.close(); }
    });
})();
</script>
@endpush
