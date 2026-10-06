<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>First-Time Setup</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/forgot-password.css') }}">
</head>
<body>

<div class="login-card" style="max-width:440px;">

    <div class="login-icon">
        <i class="fa-solid fa-user-shield"></i>
    </div>

    <h2>First-Time Setup</h2>
    <p class="subtitle">Create the Super Admin account. This page is only available until that account exists.</p>

    @if ($errors->any())
        <div class="error-box">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('setup') }}">
        @csrf
        <div class="input-group">
            <label>Full Name</label>
            <input type="text" name="full_name" value="{{ old('full_name') }}" required autofocus>
        </div>
        <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required>
        </div>
        <div class="input-group">
            <label>Password (at least 8 characters)</label>
            <input type="password" name="password" minlength="8" required>
        </div>
        <div class="input-group">
            <label>Confirm Password</label>
            <input type="password" name="password_confirmation" minlength="8" required>
        </div>
        <div class="input-group">
            <label>Security Question (for password recovery)</label>
            <input type="text" name="security_question" value="{{ old('security_question') }}" placeholder="A question only you can answer" required>
        </div>
        <div class="input-group">
            <label>Security Answer</label>
            <input type="text" name="security_answer" minlength="4" required autocomplete="off">
        </div>
        <button type="submit" class="login-btn">Create Super Admin</button>
    </form>

</div>

</body>
</html>
