{{-- Super Admin dashboard only: scan photos the daily prune deletes within
     the next few days (App\Domain\Attendance\ScanPhotos::upcomingDeletion).
     $scanPhotoDeletion: {count, next_deletion_at, next_deletion_count}. --}}
@if ($scanPhotoDeletion['count'] > 0)
    <div class="kiosk-alert-banner scan-deletion-banner" role="alert">
        <h3><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Scan Photos About to Be Deleted</h3>
        <p>
            <strong>{{ $scanPhotoDeletion['count'] }} scan {{ Str::plural('photo', $scanPhotoDeletion['count']) }}</strong>
            will be deleted within the next {{ \App\Domain\Attendance\ScanPhotos::DELETION_WARNING_DAYS }} days.
            Next deletion: <strong>{{ $scanPhotoDeletion['next_deletion_at']->format('l, M j, Y \a\t g:i A') }}</strong>
            ({{ $scanPhotoDeletion['next_deletion_count'] }} {{ Str::plural('photo', $scanPhotoDeletion['next_deletion_count']) }}).
        </p>
        <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Scan photos are kept for {{ \App\Domain\Attendance\ScanPhotos::RETENTION_DAYS }} days. Review any you still need on <a href="{{ route('superadmin.scan-photos') }}">Scan Photos</a> before then.</span></p>
    </div>
@endif
