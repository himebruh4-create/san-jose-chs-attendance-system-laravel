@php
    $message = $message ?? '';
    $messageType = $messageType ?? 'error';
    // The same-password error uses the error modal instead of the inline box.
    $isSamePasswordError = $message === 'New password must be different from your current password.';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/forgot-password.css') }}">
</head>
<body>

<div class="login-card">

    <div class="login-icon">
        <i class="fa-solid fa-key"></i>
    </div>

    <h2>Forgot Password</h2>
    <p class="subtitle">Account password recovery</p>

    @if ($message && ! $isSamePasswordError)
        <div class="{{ $messageType === 'success' ? 'success-box' : 'error-box' }}">{{ $message }}</div>
    @endif

    @if ($step === 'email')

        <form method="POST" action="{{ route('password.forgot') }}">
            @csrf
            <input type="hidden" name="step" value="email">
            <div class="input-group">
                <label>Registered Email</label>
                <input type="email" name="email" placeholder="you@example.com" required>
            </div>
            <button type="submit" class="login-btn">Continue</button>
        </form>

    @elseif ($step === 'question')

        <div class="security-question-box">{{ $securityQuestion }}</div>

        <form method="POST" action="{{ route('password.forgot') }}">
            @csrf
            <input type="hidden" name="step" value="question">
            <div class="input-group">
                <label>Your Answer</label>
                <input type="text" name="security_answer" required autofocus autocomplete="off">
            </div>
            <button type="submit" class="login-btn">Verify Answer</button>
        </form>

    @elseif ($step === 'reset')

        <form method="POST" action="{{ route('password.forgot') }}">
            @csrf
            <input type="hidden" name="step" value="reset">
            <div class="input-group">
                <label>New Password</label>
                <input type="password" name="new_password" minlength="8" required autofocus>
            </div>
            <div class="input-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" minlength="8" required>
            </div>
            <button type="submit" class="login-btn">Reset Password</button>
        </form>

    @elseif ($step === 'done' || $step === 'request_sent')

        <a href="{{ route('login') }}" class="login-btn" style="display:block; text-decoration:none; box-sizing:border-box;">
            Go to Login
        </a>

    @endif

    <a href="{{ route('login') }}" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>

</div>

<!-- APPLICATION ERROR MODAL -->
<div class="modal-backdrop" id="appErrorModal">
    <div class="modal">
        <button class="modal-close" type="button" onclick="closeErrorModal()"><i class="fa-solid fa-xmark"></i></button>
        <h3>Something Went Wrong</h3>
        <p id="appErrorModalMessage" style="color:#8a7d5c; font-size:14px; margin:0;"></p>
        <div class="modal-actions">
            <button type="button" onclick="closeErrorModal()">OK</button>
        </div>
    </div>
</div>

<script>
function showErrorModal(message) {
    document.getElementById('appErrorModalMessage').textContent = message || 'An error occurred.';
    document.getElementById('appErrorModal').classList.add('active');
    document.body.classList.add('modal-open');
}

function closeErrorModal() {
    document.getElementById('appErrorModal').classList.remove('active');
    document.body.classList.remove('modal-open');
}

@if ($isSamePasswordError)
document.addEventListener('DOMContentLoaded', function () {
    showErrorModal(@json($message));
});
@endif
</script>

</body>
</html>
