<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-base" content="{{ url('/') }}">
<title>Personnel Attendance Dashboard</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/kiosk.css') }}">
<script src="{{ asset_v('js/app-shell.js') }}"></script>
</head>

<body>

<div class="menu-trigger" onclick="toggleSidebar()">
    <i class="fa-solid fa-bars"></i>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebarDrawer">
    <div class="sidebar-header">
        <button class="sidebar-close" onclick="closeSidebar()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <img src="{{ asset('img/logo.png') }}" alt="School Logo" class="sidebar-logo">
        <h2>San Jose CHS</h2>
        <p>Personnel Attendance</p>
    </div>
    <div class="sidebar-menu">
        <a href="#scanSection" onclick="closeSidebar()"><i class="fa-solid fa-barcode"></i> <span>Scan</span></a>
        <a href="{{ route('login') }}"><i class="fa-solid fa-right-to-bracket"></i> <span>Login</span></a>
    </div>
    <div class="sidebar-footer">
        &copy; {{ date('Y') }} San Jose CHS
    </div>
</div>

<div class="dashboard-content">

    <div class="page-header">
        <div class="page-title">
            <div class="eyebrow">Personnel Attendance</div>
            <h1>Attendance Dashboard</h1>
            <p>Scan or enter your barcode to record your time in and out for today.</p>
        </div>
        <div class="live-clock">
            <div class="clock-time" id="liveClockTime">--:--:-- --</div>
            <div class="clock-date" id="liveClockDate">Loading...</div>
        </div>
    </div>

    <div class="scan-card" id="scanSection">

        <div class="camera-box">
            <div class="camera-frame">
                <video id="cameraPreview" autoplay muted playsinline></video>
            </div>
            <div id="cameraStatus" class="camera-status camera-off">Starting camera…</div>
        </div>

        <div class="barcode-section">
            <input type="text" id="barcodeInput" placeholder="Scan or enter barcode..." autofocus inputmode="numeric" maxlength="6" autocomplete="off">
            <button onclick="processBarcode()">
                <i class="fa-solid fa-check"></i> Enter
            </button>
        </div>

        <div class="scan-hint">
            <i class="fa-solid fa-circle-info"></i>
            Lost your ID? You can type your barcode number manually.
        </div>

    </div>

</div>

<!-- MODAL -->
<div id="modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span id="scanBadge" class="scan-badge" hidden></span>
        <h2 id="title"></h2>
        <div id="message"></div>
        <div id="modalCountdown" class="modal-countdown" hidden></div>
    </div>
</div>

{{-- Camera health thresholds, intervals and the bundled face detector (App\Support\KioskHealth). --}}
<script>
window.KIOSK_CAMERA = @json(\App\Support\KioskHealth::kioskConfig());
</script>
<script src="{{ asset_v('js/kiosk.js') }}"></script>

</body>
</html>
