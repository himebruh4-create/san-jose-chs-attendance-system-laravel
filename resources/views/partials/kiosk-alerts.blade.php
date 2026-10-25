{{-- Open kiosk camera / offline alerts (Super Admin and Admin dashboards):
     text only, never a photo. $kioskAlerts from KioskHealth::openAlerts().
     Each closes on its own once the kiosk reports its camera working again
     (or checks in again, for "offline"). --}}
@if ($kioskAlerts->isNotEmpty())
    <div class="kiosk-alert-banner" role="alert">
        <h3><i class="fa-solid fa-video-slash" aria-hidden="true"></i> Kiosk {{ $kioskAlerts->count() > 1 ? 'Problems' : 'Problem' }}</h3>
        <ul>
            @foreach ($kioskAlerts as $alert)
                <li><strong>{{ $alert->kiosk->name }}:</strong> {{ $alert->message() }}</li>
            @endforeach
        </ul>
        <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Scans are still recorded. The alert closes by itself once the kiosk works normally again.</span></p>
    </div>
@endif
