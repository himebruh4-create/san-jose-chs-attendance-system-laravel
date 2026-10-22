{{-- Super Admin: failed Forgot Password tries on this account since the last
     login (set by LoginController, shown once on the first page after it). --}}
@php $recoveryWarning = session()->pull('recovery_warning'); @endphp

@if ($recoveryWarning)
    <div class="recovery-warning" role="alert">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div>
            <strong>{{ $recoveryWarning['count'] }} failed password-recovery {{ $recoveryWarning['count'] === 1 ? 'attempt' : 'attempts' }} since your last login</strong>
            (latest: {{ date('M j, Y g:i A', strtotime($recoveryWarning['latest'])) }}).
            If this wasn't you, generate new recovery codes in Settings &rarr; Account Management and change your security questions in My Account.
        </div>
        <button type="button" class="recovery-warning-close" aria-label="Dismiss" onclick="this.closest('.recovery-warning').remove()">&times;</button>
    </div>
@endif
