{{-- "Your account was signed in on another device" (one device per account,
     App\Support\ActiveSessions). public/js/session-guard.js checks every 10
     seconds and fills in the other device and the countdown. It cannot be
     dismissed: Stay logged in, Log out, or logged out when the countdown
     ends. $checkNow: check at once (the page shown to a replaced session). --}}
<div class="session-guard-backdrop" id="sessionGuard" data-check-now="{{ ($checkNow ?? false) ? '1' : '0' }}" hidden>
    <div class="session-guard-box" role="alertdialog" aria-modal="true" aria-labelledby="sessionGuardTitle" aria-describedby="sessionGuardBody">
        <div class="session-guard-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
        <h3 id="sessionGuardTitle">Your account was signed in on another device</h3>
        <p id="sessionGuardBody"></p>
        <p class="session-guard-note">If this wasn't you, choose <strong>Stay logged in</strong> and change your password right away.</p>
        <p class="session-guard-countdown" id="sessionGuardCountdown" aria-live="polite"></p>
        <div class="session-guard-actions">
            <button type="button" class="session-guard-logout" id="sessionGuardLogout">Log out</button>
            <button type="button" class="session-guard-stay" id="sessionGuardStay">Stay logged in</button>
        </div>
    </div>
    <form id="sessionGuardLogoutForm" method="POST" action="{{ route('logout') }}" hidden>@csrf</form>
</div>
<script src="{{ asset_v('js/session-guard.js') }}"></script>
