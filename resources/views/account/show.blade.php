@extends('layouts.app')

@section('title', 'My Account')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/my-account.css') }}">
@endpush

@php
    $roleLabels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'principal' => 'Principal'];
    $roleLabel = $roleLabels[$account->role] ?? ucfirst($account->role);
@endphp

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>My Account</h1>
        <p>Manage your own login credentials.</p>
    </div>

    <div class="account-grid">

    <div class="account-col">
        <div class="card">
            <h3><i class="fa-solid fa-lock"></i> Change Password</h3>
            <p class="card-sub">Signed in as {{ $account->email }}</p>

            <form method="POST" action="{{ route('account.password') }}">
                @csrf
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" minlength="8" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" minlength="8" required>
                </div>
                <button type="submit" class="save-btn">Change Password</button>
            </form>

            @if ($account->role !== 'superadmin')
                <p class="forgot-password-link">
                    <a href="#" onclick="document.getElementById('forgotPasswordNotice').style.display='block'; return false;">Forgot Password?</a>
                </p>
                <div id="forgotPasswordNotice" class="forgot-password-notice">
                    Forgot your password? Please contact the Super Admin to reset your account password.
                </div>
            @endif
        </div>

        @if ($account->role === 'superadmin')
        <div class="card">
            <h3><i class="fa-solid fa-shield-halved"></i> Security Question</h3>
            <p class="card-sub">Used to recover your account via "Forgot Password" on the login page.</p>

            @if ($account->security_question)
                <p class="current-question-note">Current question: <strong>{{ $account->security_question }}</strong></p>
            @else
                <p class="current-question-note">No security question set yet — Forgot Password will not work until you set one.</p>
            @endif

            <form method="POST" action="{{ route('account.security-question') }}">
                @csrf
                <div class="form-group">
                    <label>Security Question</label>
                    <input type="text" name="security_question" placeholder="e.g. What is the name of your first school?" required>
                </div>
                <div class="form-group">
                    <label>Answer</label>
                    <input type="text" name="security_answer" minlength="4" required autocomplete="off">
                </div>
                <button type="submit" class="save-btn">Save Security Question</button>
            </form>
        </div>
        @endif

    </div>

    <div class="account-col">

        <div class="card">
            <h3><i class="fa-solid fa-id-card"></i> Account Information</h3>
            <p class="card-sub">Details for your own logged-in account.</p>

            <div class="info-row">
                <span class="info-label">Account Role</span>
                <span class="info-value">{{ $roleLabel }}</span>
            </div>

            <div class="info-row">
                <span class="info-label">Email Address</span>
                <span class="info-value">{{ $account->email }}</span>
            </div>

            <div class="info-row">
                <span class="info-label">Account Status</span>
                <span class="info-value"><span class="status-pill active">&#128994; Active</span></span>
            </div>

            @if ($account->role === 'superadmin')
                <p class="protected-note">
                    <i class="fa-solid fa-shield-halved"></i>
                    Super Admin account is protected and cannot be deactivated or archived.
                </p>
            @endif
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-user-shield"></i> Security</h3>
            <p class="security-note">
                Keep your account credentials private and do not share your password with other users.
            </p>
            @if ($account->role === 'superadmin')
                <p class="security-note">
                    The Super Admin account has system-wide management access. Handle it with extra care.
                </p>
            @endif
        </div>

    </div>

    </div>

</div>

<div id="toast" class="toast"></div>

<!-- APPLICATION ERROR MODAL -->
<div class="modal-backdrop" id="appErrorModal">
    <div class="modal">
        <h3>Something Went Wrong</h3>
        <p id="appErrorModalMessage" style="color:#8a7d5c; font-size:14px; margin:0;"></p>
        <div class="modal-actions">
            <button type="button" class="save-btn" onclick="closeErrorModal()">OK</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

function showErrorModal(message) {
    document.getElementById('appErrorModalMessage').textContent = message || 'An error occurred.';
    document.getElementById('appErrorModal').classList.add('active');
    lockBodyScroll();
}

function closeErrorModal() {
    document.getElementById('appErrorModal').classList.remove('active');
    unlockBodyScroll();
}

@if (session('success'))
document.addEventListener('DOMContentLoaded', function () {
    showToast(@json(session('success')));
});
@elseif (session('error'))
document.addEventListener('DOMContentLoaded', function () {
    showErrorModal(@json(session('error')));
});
@endif
</script>
@endpush
