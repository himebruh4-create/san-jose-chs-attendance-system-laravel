@extends('layouts.app')

@section('title', 'Settings - Super Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/superadmin-settings.css') }}">
@endpush

@section('content')
<div class="main">

    <h1>Settings</h1>

    <div class="card" id="attendanceToggleCard">

        <h2>Attendance Recording</h2>
        <p class="subtitle">
            Turn this off during Summer Break or extended closures to stop the system from recording scans.
        </p>

        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">

            <span id="recordingStatusBadge" class="status-badge">Loading...</span>

            <button type="button" id="toggleRecordingBtn" class="toggle-btn" onclick="toggleAttendanceRecording()">
                Loading...
            </button>

        </div>

        <div id="reasonFieldWrapper" class="reason-field">
            <label>Reason (shown to teachers when scanning)</label>
            <input type="text" id="disabledReasonInput" placeholder="e.g. Summer Break 2027">
        </div>

    </div>

    <div class="card" id="lunchBreakCard">

    <h2>School Lunch Break</h2>
    <p class="subtitle">
        Breaks are paid: they are part of the shift and are never deducted from hours.
        The lunch window below is the default for ordinary day schedules (for example 7:00 AM - 3:00 PM) and is shown
        on the DTR as an automatic (Auto) AM Departure / PM Arrival. Personnel are not asked to scan breaks.
        Set a different paid break for other schedules/shifts in the table below - it is applied automatically to
        anyone assigned that schedule.
    </p>

    <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">

        <div>
            <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238;">Lunch Out (AM Departure)</label>
            <input type="time" id="lunchOutInput" style="padding:10px 12px; border:1px solid #e6dcc4; border-radius:8px;">
        </div>

        <div>
            <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238;">Lunch In (PM Arrival)</label>
            <input type="time" id="lunchInInput" style="padding:10px 12px; border:1px solid #e6dcc4; border-radius:8px;">
        </div>

        <button type="button" class="toggle-btn on" onclick="saveLunchBreak()">
            <i class="fa-solid fa-floppy-disk"></i> Save Lunch Break
        </button>

    </div>
    <p class="subtitle" style="margin-top:4px;">
        Saving here also saves any Paid Break by Schedule changes below — both settings are stored together.
    </p>

    <h3 style="margin:22px 0 4px; font-size:15px; color:#5c5238;">Paid Break by Schedule</h3>
    <p class="subtitle" style="margin-bottom:10px;">
        Matches the personnel's assigned Time In / Time Out exactly — used for shift schedules (guards) whose paid break differs
        from the shared School Lunch Break above. Shift personnel do not scan breaks: only their actual IN and OUT are recorded
        and shown, and the configured break is handled internally for hours.
    </p>

    <div class="table-scroll sticky-table-wrapper">
        <table class="dept-table" id="scheduleBreakTable">
            <thead>
                <tr>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Break Start</th>
                    <th>Break End</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="scheduleBreakRows"></tbody>
        </table>
    </div>

    <div style="margin-top:10px;">
        <button type="button" class="add-option-btn" onclick="addScheduleBreakRow()">
            <i class="fa-solid fa-plus"></i> Add Schedule Break
        </button>
        <button type="button" class="toggle-btn on" onclick="saveLunchBreak()">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
    </div>
    <p class="subtitle" style="margin-top:6px;">
        Added and removed rows above only take effect after Save Changes is clicked.
        This also saves the School Lunch Break settings above at the same time — both settings are stored together.
    </p>

</div>

    <!-- ===== ACCOUNT MANAGEMENT ===== -->

    <div class="card" id="accountManagementCard">

        <div class="dept-card-header">
            <div>
                <h2>Account Management</h2>
                <p class="subtitle">
                    Create and view Admin and Principal logins. Passwords are never shown here.
                </p>
            </div>
            <button type="button" class="add-option-btn" onclick="openAddAccountModal()">
                <i class="fa-solid fa-user-plus"></i> Add Account
            </button>
        </div>

        @if ($accounts->isEmpty())

            <div class="dept-empty-state">
                No Admin or Principal accounts yet. Use "Add Account" to create one.
            </div>

        @else

            <div class="table-scroll sticky-table-wrapper">
            <table class="dept-table">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Date Created</th>
                        <th>Last Login</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $account)
                    <tr>
                        <td>{{ $account->full_name }}</td>
                        <td>{{ $account->email }}</td>
                        <td style="text-transform: capitalize;">{{ $account->role }}</td>
                        <td>{{ $account->created_at?->format('M j, Y') }}</td>
                        <td>{{ $account->last_login_at?->format('M j, Y g:i A') ?? '—' }}</td>
                        <td>
                            <button type="button" class="add-option-btn" style="padding:6px 12px; font-size:12.5px;" onclick="openResetPasswordModal({{ (int) $account->id }}, {{ Js::from($account->full_name) }}, {{ Js::from($account->email) }})">Reset Password</button>
                            <button type="button" class="deactivate-option-btn" onclick="openDeleteAccountModal({{ (int) $account->id }}, {{ Js::from($account->full_name) }})">Archive</button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>

        @endif

    </div>

    <!-- ===== MANAGE SUBJECTS & POSITIONS ===== -->

    <div class="card" id="departmentOptionsCard">

        <div class="dept-card-header">
            <div>
                <h2>Manage Positions</h2>
                <p class="subtitle">
                    Positions appear in the Add/Edit Personnel form's Position dropdown, grouped by
                    Personnel Type. 
                </p>
            </div>
            <button type="button" class="add-option-btn" onclick="openAddOptionModal()">
                <i class="fa-solid fa-plus"></i> Add
            </button>
        </div>

        <div class="dept-tabs">
            <button type="button" class="dept-tab-btn active" id="deptTabTeaching" onclick="switchDeptTab('Teaching')">
                Teaching Staff
            </button>
            <button type="button" class="dept-tab-btn" id="deptTabNonTeaching" onclick="switchDeptTab('Non-Teaching')">
                Non-Teaching Staff / School Administrator
            </button>
        </div>

        <div class="table-scroll">
        <table class="dept-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Personnel Type</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="departmentOptionsTableBody">
                <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>
        </div>

    </div>

    <!-- ===== RECYCLE BIN ===== -->

    <div class="card" id="recycleBinCard">

        <div class="dept-card-header">
            <div>
                <h2>Recycle Bin</h2>
                <p class="subtitle">
                    Deleted records are kept here until restored or permanently removed. Deleting a record elsewhere in the system moves it here — it is never removed immediately.
                </p>
            </div>
        </div>

        <div class="dept-tabs" id="recycleBinTabs">
            <button type="button" class="dept-tab-btn active" data-type="teacher" onclick="switchRecycleBinTab('teacher', this)">Personnel</button>
            <button type="button" class="dept-tab-btn" data-type="personnel_on_leave" onclick="switchRecycleBinTab('personnel_on_leave', this)">Personnel on Leave</button>
            <button type="button" class="dept-tab-btn" data-type="school_event" onclick="switchRecycleBinTab('school_event', this)">School Schedule Changes</button>
            <button type="button" class="dept-tab-btn" data-type="attendance_adjustment" onclick="switchRecycleBinTab('attendance_adjustment', this)">Attendance Adjustments</button>
            <button type="button" class="dept-tab-btn" data-type="position" onclick="switchRecycleBinTab('position', this)">Positions</button>
            <button type="button" class="dept-tab-btn" data-type="account" onclick="switchRecycleBinTab('account', this)">Accounts</button>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:10px;">
            <button type="button" id="recycleBinDeleteAllBtn" class="deactivate-option-btn" onclick="confirmRecycleBinDeleteAll()" disabled>
                <i class="fa-solid fa-trash-can"></i> Delete All
            </button>
        </div>

        <div class="table-scroll">
        <table class="dept-table">
            <thead>
                <tr>
                    <th>Record</th>
                    <th>Details</th>
                    <th>Deleted On</th>
                    <th>Deleted By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="recycleBinTableBody">
                <tr><td colspan="5" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>
        </div>

    </div>

    <!-- ===== DATABASE BACKUP (Super Admin only — this page is Super Admin only,
         and every backup endpoint enforces it again server-side) ===== -->

    <div class="card" id="databaseBackupCard">

        <div class="dept-card-header">
            <div>
                <h2>Database Backup</h2>
                <p class="subtitle">
                    Create a complete backup of the system database for safekeeping.
                </p>
            </div>
            <button type="button" class="add-option-btn" id="createBackupBtn" onclick="createDatabaseBackup()">
                <i class="fa-solid fa-database"></i> Create Database Backup
            </button>
        </div>

        <div class="table-scroll">
        <table class="dept-table">
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Date Created</th>
                    <th>Size</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="databaseBackupTableBody">
                <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>
        </div>

    </div>

    <!-- DISABLE RECORDING REASON MODAL -->
<div class="modal-backdrop" id="disableReasonModal">
    <div class="modal">
        <button class="modal-close" type="button" onclick="closeDisableReasonModal()">×</button>
        <h3>Disable Attendance Recording</h3>
        <p style="color: #8a7d5c; font-size: 14px; margin-bottom: 15px;">
            Enter a reason teachers will see when they try to scan while recording is disabled.
        </p>
        <input
            type="text"
            id="modalReasonInput"
            placeholder="e.g. Summer Break 2027"
            style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px; margin-bottom: 20px;"
        >
        <div class="modal-actions">
            <button type="button" onclick="closeDisableReasonModal()">Cancel</button>
            <button type="button" style="background: #c62828; color: white;" onclick="confirmDisableRecording()">
                Disable Recording
            </button>
        </div>
    </div>
</div>

    <!-- ADD POSITION MODAL -->
    <div class="modal-backdrop" id="addOptionModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeAddOptionModal()">×</button>
            <h3>Add Position</h3>
            <p style="color: #8a7d5c; font-size: 14px; margin-bottom: 15px;">
                This will immediately appear as a choice in the Add/Edit Personnel form.
            </p>

            <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">Name</label>
            <input type="text" id="addOptionNameInput" placeholder="e.g. Teacher I" data-capitalize="words">

            <div style="margin-top:12px;">
                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">Personnel Type</label>
                <select id="addOptionPersonnelTypeSelect" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px;">
                    <option value="">Select Personnel Type</option>
                    <option value="Teaching">Teaching Staff</option>
                    <option value="Non-Teaching">Non-Teaching Staff / School Administrator</option>
                </select>
            </div>

            <div class="modal-actions" style="margin-top: 15px;">
                <button type="button" onclick="closeAddOptionModal()">Cancel</button>
                <button type="button" style="background: #14390f; color: white;" onclick="saveNewOption()">
                    Save
                </button>
            </div>
        </div>
    </div>

    <!-- DEACTIVATE SUBJECT/POSITION MODAL -->
    <div class="modal-backdrop" id="deactivateOptionModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeDeactivateOptionModal()">×</button>
            <h3>Deactivate Option</h3>
            <p style="color: #8a7d5c; font-size: 14px;">
                Are you sure you want to deactivate <strong id="deactivateOptionName" style="color:#14390f;"></strong>?
                It will no longer appear in the Add/Edit Personnel form, but existing personnel records that reference it are kept as-is.
            </p>
            <p style="color: #8a7d5c; font-size: 13px;">
                This will move it to the Recycle Bin and will not be permanently deleted. You can restore it later.
            </p>
            <div class="modal-actions" style="margin-top: 15px;">
                <button type="button" onclick="closeDeactivateOptionModal()">Cancel</button>
                <button type="button" style="background: #c62828; color: white;" onclick="confirmDeactivateOption()">
                    Deactivate
                </button>
            </div>
        </div>
    </div>

    <!-- REMOVE SCHEDULE BREAK ROW MODAL -->
    <div class="modal-backdrop" id="scheduleBreakRemoveModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeScheduleBreakRemoveModal()">×</button>
            <h3>Remove Schedule Break?</h3>
            <p style="color: #8a7d5c; font-size: 14px;">
                Are you sure you want to remove this schedule break?
            </p>
            <div class="modal-actions" style="margin-top: 15px;">
                <button type="button" onclick="closeScheduleBreakRemoveModal()">Cancel</button>
                <button type="button" style="background: #c62828; color: white;" onclick="confirmRemoveScheduleBreak()">
                    Remove
                </button>
            </div>
        </div>
    </div>

    <!-- DELETE ACCOUNT MODAL -->
    <div class="modal-backdrop" id="deleteAccountModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeDeleteAccountModal()">×</button>
            <h3>Archive Account</h3>
            <p style="color: #8a7d5c; font-size: 14px;">
                Are you sure you want to archive <strong id="deleteAccountName" style="color:#14390f;"></strong>?
                They will no longer be able to log in until restored.
            </p>
            <p style="color: #8a7d5c; font-size: 13px;">
                This record will be moved to the Recycle Bin and will not be permanently deleted. You can restore it later.
            </p>

            <form method="POST" action="{{ route('superadmin.settings.accounts.archive') }}">
                @csrf
                <input type="hidden" name="account_id" id="deleteAccountId">
                <div class="modal-actions" style="margin-top: 15px;">
                    <button type="button" onclick="closeDeleteAccountModal()">Cancel</button>
                    <button type="submit" style="background: #c62828; color: white;">Archive</button>
                </div>
            </form>
        </div>
    </div>

    <!-- REVOKE KIOSK MODAL -->
    <div class="modal-backdrop" id="revokeKioskModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeRevokeKioskModal()">×</button>
            <h3>Revoke Kiosk</h3>
            <p style="color: #8a7d5c; font-size: 14px;">
                Are you sure you want to revoke <strong id="revokeKioskName" style="color:#14390f;"></strong>?
                It will stop recording attendance immediately.
            </p>
            <p style="color: #8a7d5c; font-size: 13px;">
                To use this computer as a kiosk again, register it again from this page.
            </p>

            <form method="POST" action="{{ route('superadmin.settings.kiosk.revoke') }}">
                @csrf
                <input type="hidden" name="id" id="revokeKioskId">
                <div class="modal-actions" style="margin-top: 15px;">
                    <button type="button" onclick="closeRevokeKioskModal()">Cancel</button>
                    <button type="submit" style="background: #c62828; color: white;">Revoke</button>
                </div>
            </form>
        </div>
    </div>

    <!-- RECYCLE BIN: PERMANENTLY DELETE MODAL -->
    <div class="modal-backdrop" id="recycleBinDeleteModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeRecycleBinDeleteModal()">×</button>
            <h3>Permanently Delete</h3>
            <p style="color: #8a7d5c; font-size: 14px;">
                Are you sure you want to permanently delete this <strong id="recycleBinDeleteLabel" style="color:#14390f;"></strong>?
                This action cannot be undone.
            </p>
            <div class="modal-actions" style="margin-top: 15px;">
                <button type="button" onclick="closeRecycleBinDeleteModal()">Cancel</button>
                <button type="button" style="background: #c62828; color: white;" onclick="executeRecycleBinPermanentDelete()">
                    Permanently Delete
                </button>
            </div>
        </div>
    </div>

    <!-- RECYCLE BIN: DELETE ALL (per category) MODAL -->
    <div class="modal-backdrop" id="recycleBinDeleteAllModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeRecycleBinDeleteAllModal()">×</button>
            <h3>Delete All Archived Records?</h3>
            <p style="color: #8a7d5c; font-size: 14px;">
                This will permanently delete all archived <strong id="recycleBinDeleteAllLabel" style="color:#14390f;"></strong> records.
                This action cannot be undone.
            </p>
            <div class="modal-actions" style="margin-top: 15px;">
                <button type="button" onclick="closeRecycleBinDeleteAllModal()">Cancel</button>
                <button type="button" style="background: #c62828; color: white;" onclick="executeRecycleBinDeleteAll()">
                    Delete All
                </button>
            </div>
        </div>
    </div>

    <!-- ADD ACCOUNT MODAL -->
    <div class="modal-backdrop" id="addAccountModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeAddAccountModal()">×</button>
            <h3>Add Account</h3>
            <p style="color: #8a7d5c; font-size: 14px; margin-bottom: 15px;">
                Create a new Admin or Principal login.
            </p>

            <form method="POST" action="{{ route('superadmin.settings.accounts.create') }}">
                @csrf

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">Full Name</label>
                <input type="text" name="full_name" required data-capitalize="words" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px; margin-bottom: 12px;">

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">Email</label>
                <input type="email" name="email" required style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px; margin-bottom: 12px;">

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">Password</label>
                <div class="password-field-wrapper">
                    <input type="password" name="password" id="newAccountPassword" minlength="8" required style="width: 100%; box-sizing: border-box; padding: 10px 40px 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px;">
                    <i class="fa-solid fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility('newAccountPassword', this)"></i>
                </div>

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px; margin-top: 12px;">Confirm Password</label>
                <div class="password-field-wrapper">
                    <input type="password" name="confirm_password" id="newAccountConfirmPassword" minlength="8" required style="width: 100%; box-sizing: border-box; padding: 10px 40px 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px;">
                    <i class="fa-solid fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility('newAccountConfirmPassword', this)"></i>
                </div>

                <div style="margin-bottom: 12px;"></div>

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">Role</label>
                <select name="role" required style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px; margin-bottom: 6px;">
                    <option value="">Select role</option>
                    <option value="admin">Admin</option>
                    <option value="principal">Principal</option>
                </select>

                <div class="modal-actions" style="margin-top: 15px;">
                    <button type="button" onclick="closeAddAccountModal()">Cancel</button>
                    <button type="submit" style="background: #14390f; color: white;">
                        Create Account
                    </button>
                </div>

            </form>
        </div>
    </div>

    <!-- RESET PASSWORD MODAL (Admin/Principal only) -->
    <div class="modal-backdrop" id="resetPasswordModal">
        <div class="modal">
            <button class="modal-close" type="button" onclick="closeResetPasswordModal()">×</button>
            <h3>Reset Password</h3>
            <p style="color: #8a7d5c; font-size: 14px; margin-bottom: 15px;">
                Set a new password for <strong id="resetPasswordName" style="color:#14390f;"></strong>
                (<span id="resetPasswordEmail"></span>). They will not be asked for their old password.
            </p>

            <form method="POST" action="{{ route('superadmin.settings.accounts.reset-password') }}">
                @csrf
                <input type="hidden" name="account_id" id="resetPasswordAccountId">

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px;">New Password</label>
                <div class="password-field-wrapper">
                    <input type="password" name="new_password" id="resetAccountPassword" minlength="8" required style="width: 100%; box-sizing: border-box; padding: 10px 40px 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px;">
                    <i class="fa-solid fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility('resetAccountPassword', this)"></i>
                </div>

                <label style="display:block; font-weight:600; margin-bottom:6px; color:#5c5238; font-size: 13px; margin-top: 12px;">Confirm New Password</label>
                <div class="password-field-wrapper">
                    <input type="password" name="confirm_password" id="resetAccountConfirmPassword" minlength="8" required style="width: 100%; box-sizing: border-box; padding: 10px 40px 10px 12px; border: 1px solid #e6dcc4; border-radius: 8px;">
                    <i class="fa-solid fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility('resetAccountConfirmPassword', this)"></i>
                </div>

                <div class="modal-actions" style="margin-top: 15px;">
                    <button type="button" onclick="closeResetPasswordModal()">Cancel</button>
                    <button type="submit" style="background: #14390f; color: white;">
                        Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>


    <!-- ===== KIOSK DEVICES (new) ===== -->

    <div class="card" id="kioskDevicesCard">

        <div class="dept-card-header">
            <div>
                <h2>Kiosk Devices</h2>
                <p class="subtitle">
                    Only computers registered here can record attendance scans. To set up a kiosk, log in as Super Admin
                    on that computer, register it here, then log out and open the kiosk page.
                    A kiosk other than the server needs the HTTPS address for its webcam: install the
                    <a href="{{ route('certificate') }}" target="_blank">kiosk certificate</a> on it first.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('superadmin.settings.kiosk.register') }}" class="kiosk-register-row">
            @csrf
            <input type="text" name="name" maxlength="100" placeholder="Kiosk name, e.g. Main Entrance">
            <button type="submit" class="add-option-btn">
                <i class="fa-solid fa-desktop"></i> Register this browser as a kiosk
            </button>
            @if ($currentKioskId)
                <span class="kiosk-current">This browser is already a kiosk</span>
            @endif
        </form>

        <div class="table-scroll" style="margin-top:14px;">
        <table class="dept-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Registered</th>
                    <th>Last Used</th>
                    <th>Last IP</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($kiosks as $kiosk)
                    <tr class="{{ $kiosk->revoked_at ? 'kiosk-revoked' : '' }}">
                        <td>
                            {{ $kiosk->name }}
                            @if ($kiosk->id === $currentKioskId)
                                <span class="kiosk-current">This browser</span>
                            @endif
                        </td>
                        <td>{{ $kiosk->created_at?->format('M j, Y g:i A') }}<br><small>by {{ $kiosk->created_by }}</small></td>
                        <td>{{ $kiosk->last_seen_at?->format('M j, Y g:i A') ?? 'Never' }}</td>
                        <td>{{ $kiosk->last_ip ?? '—' }}</td>
                        <td>{{ $kiosk->revoked_at ? 'Revoked '.$kiosk->revoked_at->format('M j, Y') : 'Active' }}</td>
                        <td>
                            @unless ($kiosk->revoked_at)
                                <button type="button" class="deactivate-option-btn"
                                        data-kiosk-id="{{ $kiosk->id }}" data-kiosk-name="{{ $kiosk->name }}"
                                        onclick="openRevokeKioskModal(this)">Revoke</button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;">No kiosk registered yet. Attendance cannot be recorded until one is.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>

    </div>

    <!-- ===== AUDIT LOG (new) ===== -->

    <div class="card" id="auditLogCard">

        <div class="dept-card-header">
            <div>
                <h2>Audit Log</h2>
                <p class="subtitle">The latest 200 changes: logins, attendance corrections, personnel, accounts and settings.</p>
            </div>
            <button type="button" class="add-option-btn" onclick="loadAuditLog()">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>

        <div class="table-scroll audit-scroll sticky-table-wrapper">
        <table class="dept-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>Record</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="auditLogTableBody">
                <tr><td colspan="5" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>
        </div>

    </div>

</div>

<div id="toast" class="toast"></div>

<!-- APPLICATION ERROR MODAL -->
<div class="modal-backdrop" id="appErrorModal">
    <div class="modal">
        <button class="modal-close" type="button" onclick="closeErrorModal()"><i class="fa-solid fa-xmark"></i></button>
        <h3>Something Went Wrong</h3>
        <p id="appErrorModalMessage" style="color:#8a7d5c; font-size:14px;"></p>
        <div class="modal-actions">
            <button type="button" onclick="closeErrorModal()">OK</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset_v('js/superadmin-settings.js') }}"></script>
@if (session('message'))
@php $flash = ['message' => session('message'), 'type' => session('message_type', 'success')]; @endphp
<script>
document.addEventListener("DOMContentLoaded", function () {
    const flash = @json($flash);
    if (flash.message === 'New password must be different from the current password.') {
        showErrorModal(flash.message);
    } else {
        showToast(flash.message, flash.type);
    }
});
</script>
@endif
@endpush
