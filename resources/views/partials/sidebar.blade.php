@php
    $account = auth()->user();
    $role = $account?->role;

    $menus = [
        'admin' => [
            ['admin.dashboard', 'Dashboard'],
            ['admin.attendance-report', 'Attendance Report'],
            ['admin.personnel', 'Personnel List'],
            ['admin.reports', 'Reports'],
            ['account', 'My Account'],
        ],
        'superadmin' => [
            ['superadmin.dashboard', 'Dashboard'],
            ['superadmin.attendance-report', 'Attendance Report'],
            ['superadmin.adjustments', 'Attendance Adjustments'],
            ['superadmin.scan-photos', 'Scan Photos'],
            ['superadmin.personnel', 'Personnel Management'],
            ['superadmin.reports', 'Reports'],
            ['superadmin.settings', 'Settings'],
            ['account', 'My Account'],
        ],
        'principal' => [
            ['principal.dashboard', 'Dashboard'],
            ['principal.monitoring', 'Attendance Monitoring'],
            ['principal.personnel', 'Personnel List'],
            ['principal.reports', 'Reports'],
            ['account', 'My Account'],
        ],
    ];
@endphp

<!-- Mobile top bar (hamburger) -->
<div class="mobile-topbar">
    <button type="button" class="hamburger-btn" onclick="toggleSidebar()" aria-label="Open menu" aria-controls="mainSidebar" aria-expanded="false">&#9776;</button>
    <span class="mobile-topbar-title">San Jose CHS</span>
</div>

<!-- Overlay for mobile sidebar -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="mainSidebar">

    <div class="sidebar-header">
        <img src="{{ asset('img/logo.png') }}" alt="School Logo" class="sidebar-logo">
        <h2>San Jose CHS</h2>
        <p>Personnel Attendance</p>
        <span class="sidebar-role-badge">{{ $account?->roleLabel() }}</span>
    </div>

    <nav class="sidebar-menu">
        @foreach ($menus[$role] ?? [] as [$routeName, $label])
            <a href="{{ route($routeName) }}" class="{{ request()->routeIs($routeName) ? 'active' : '' }}">
                <span>{{ $label }}</span>
                @if ($routeName === 'superadmin.adjustments')
                    <span id="sidebarPendingBadge" class="sidebar-pending-badge" style="display:none;" title="Unresolved Pending Review items">0</span>
                @endif
            </a>
        @endforeach
    </nav>

    <div class="sidebar-logout">
        <a href="{{ route('logout') }}" id="sidebarLogoutLink">Logout</a>
        <form id="sidebarLogoutForm" method="POST" action="{{ route('logout') }}" style="display:none;">@csrf</form>
    </div>

</aside>
