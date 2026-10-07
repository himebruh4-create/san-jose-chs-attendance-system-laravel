<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Barcode Attendance</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/login.css') }}">
</head>
<body>

<div class="login-card">

    <div class="login-icon">
        <i class="fa-solid fa-shield-halved"></i>
    </div>

    <h2>Welcome Back</h2>
    <p class="subtitle">Admin / Super Admin / Principal Login</p>

    @if (session('error'))
        <div class="error-box">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="error-box">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="input-group">
            <label>Email</label>
            <div class="input-wrapper">
                <i class="fa-solid fa-envelope field-icon"></i>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" required autofocus>
            </div>
        </div>

        <div class="input-group">
            <label>Password</label>
            <div class="input-wrapper">
                <i class="fa-solid fa-lock field-icon"></i>
                <input type="password" name="password" id="passwordInput" placeholder="Enter your password" required>
                <i class="fa-solid fa-eye-slash toggle-password" id="togglePassword"></i>
            </div>
        </div>

        <button type="submit" class="login-btn">
            <i class="fa-solid fa-right-to-bracket"></i> Login
        </button>

    </form>

    <div style="text-align:center; margin-top:14px;">
        <a href="{{ route('password.forgot') }}" style="color:#8a7d5c; font-size:12.5px; text-decoration:none;">
            Forgot Password?
        </a>
        <span style="color:#d6cbb0; margin:0 6px;">|</span>
        <a href="{{ route('certificate') }}" style="color:#8a7d5c; font-size:12.5px; text-decoration:none;">
            Kiosk Certificate
        </a>
    </div>

    <button class="back-btn" onclick="window.location.href='{{ route('kiosk') }}'">
        <i class="fa-solid fa-arrow-left"></i> Back
    </button>

</div>

<script>
const toggle = document.getElementById('togglePassword');
const passwordInput = document.getElementById('passwordInput');

toggle.addEventListener('click', function () {

    const isHidden = passwordInput.type === 'password';

    passwordInput.type = isHidden ? 'text' : 'password';

    toggle.classList.toggle('fa-eye-slash', !isHidden);
    toggle.classList.toggle('fa-eye', isHidden);
});
</script>

</body>
</html>
