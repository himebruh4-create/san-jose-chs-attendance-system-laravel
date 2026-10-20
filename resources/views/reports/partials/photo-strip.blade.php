{{-- Super Admin only: the kiosk's "Just now" photo of each of a row's four
     scans, one cell under each scan's own column (AM Arrival ... PM
     Departure), through the Super Admin-only photos.scan route. Never
     included for Admin or Principal, and never part of a DTR. --}}
@foreach ($photos as $slot)
    @php $time = $slot['time'] ? date('g:i A', strtotime($slot['time'])) : null; @endphp
    <td class="strip-cell">
        <figure class="strip-item">
            @if ($slot['url'])
                <button type="button" class="strip-thumb" onclick="openScanPhoto(this)"
                        data-src="{{ $slot['url'] }}" data-caption="{{ $caption }} · {{ $slot['label'] }} · {{ $time }}"
                        aria-label="View the {{ $slot['label'] }} photo">
                    <img src="{{ $slot['url'] }}" alt="{{ $slot['label'] }} scan photo" loading="lazy">
                </button>
            @elseif ($time)
                <div class="strip-thumb strip-missing">No photo</div>
            @else
                <div class="strip-thumb strip-empty">&mdash;</div>
            @endif
            <figcaption>
                <strong>{{ $slot['label'] }}</strong>
                <span>{{ $time ?? 'No scan' }}</span>
            </figcaption>
        </figure>
    </td>
@endforeach
