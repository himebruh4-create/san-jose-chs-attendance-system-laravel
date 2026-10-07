<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kiosk Not Authorized</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/login.css') }}">
</head>
<body>

<div class="login-card">
    <div class="login-icon"><i class="fa-solid fa-lock"></i></div>
    <h2>This device is not a kiosk</h2>
    <p class="subtitle">
        Attendance can only be recorded on a computer the Super Admin has registered as a kiosk.
    </p>
    <p class="subtitle" style="text-align:left;">
        To register this computer: log in as Super Admin on it, open
        <strong>Settings &rarr; Kiosk Devices</strong>, and click
        <strong>Register this browser as a kiosk</strong>.
    </p>
    <p class="subtitle" style="text-align:left;">
        If this is not the server PC, install the
        <a href="{{ route('certificate') }}" style="color:#14390f;">kiosk certificate</a>
        first so the webcam works over HTTPS.
    </p>
    <a href="{{ route('login') }}" class="login-btn" style="display:block; text-decoration:none; box-sizing:border-box; text-align:center;">
        <i class="fa-solid fa-right-to-bracket"></i> Go to Login
    </a>
</div>

</body>
</html>
