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

        <div class="scan-card-header">
            <div class="scan-icon-badge">
                <i class="fa-solid fa-id-card-clip"></i>
            </div>
            <div>
                <h2>Record Attendance</h2>
                <p>Scan your ID badge or type your barcode number below</p>
            </div>
        </div>

        <div class="camera-box">
            <video id="cameraPreview" autoplay muted playsinline></video>
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

    <div class="table-card">

        <div class="table-card-header">
            <h3><i class="fa-solid fa-list-check"></i> Today's Attendance Log</h3>
            <span class="date-tag">{{ date('l, F j, Y') }}</span>
        </div>

        <div class="table-scroll">
            <table id="attendanceTable">
                <tr>
                    <th>Name</th>
                    <th>Date</th>
                    <th>AM Arrival</th>
                    <th>AM Departure</th>
                    <th>AM Status</th>
                    <th>PM Arrival</th>
                    <th>PM Departure</th>
                    <th>PM Status</th>
                    <th>DTR</th>
                </tr>

                @forelse ($rows as $row)
                    <tr data-teacher="{{ $row['teacher_id'] }}">
                        <td>{{ $row['fullname'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['am_arrival'] }}</td>
                        <td>{{ $row['am_departure'] }}</td>
                        <td>@include('kiosk.status-pill', ['status' => $row['am_status']])</td>
                        <td>{{ $row['pm_arrival'] }}</td>
                        <td>{{ $row['pm_departure'] }}</td>
                        <td>@include('kiosk.status-pill', ['status' => $row['pm_status']])</td>
                        <td>
                            <i class="fa-solid fa-eye-slash eye-icon locked"
                               data-teacher="{{ $row['teacher_id'] }}"
                               data-name="{{ $row['fullname'] }}"
                               onclick="viewDTR(this)"></i>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty-row"><i class="fa-regular fa-clock"></i>No attendance recorded yet today.</td></tr>
                @endforelse
            </table>
        </div>

    </div>

</div>

<!-- MODAL -->
<div id="modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <h2 id="title"></h2>
        <div id="message"></div>
        <div id="modalCountdown" class="modal-countdown" hidden></div>
    </div>
</div>

<script src="{{ asset_v('js/kiosk.js') }}"></script>

</body>
</html>
