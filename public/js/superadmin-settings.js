/* SETTINGS (Super Admin) — moved from the inline script of superadmin/settings.php.
   Kiosk Devices and Audit Log are new (bottom of this file). */

let recordingEnabled = true;

async function loadAttendanceRecordingStatus() {
    try {
        const response = await fetch(appUrl('data/recording-status'));
        const result = await response.json();

        if (result.success) {
            recordingEnabled = result.enabled == 1;
            renderRecordingStatus(result.reason || "");
        }
    } catch (error) {
        console.error("Failed to load recording status:", error);
    }
}

function renderRecordingStatus(reason) {

    const badge = document.getElementById("recordingStatusBadge");
    const btn = document.getElementById("toggleRecordingBtn");
    const reasonWrapper = document.getElementById("reasonFieldWrapper");
    const reasonInput = document.getElementById("disabledReasonInput");

    if (recordingEnabled) {
        badge.textContent = "ENABLED";
        badge.className = "status-badge enabled";

        btn.textContent = "Turn OFF (Start Summer Break / Closure)";
        btn.className = "toggle-btn off";

        reasonWrapper.style.display = "none";

    } else {
        badge.textContent = "DISABLED";
        badge.className = "status-badge disabled";

        btn.textContent = "Turn ON (Resume Attendance Recording)";
        btn.className = "toggle-btn on";

        reasonWrapper.style.display = "block";
        reasonInput.value = reason;
    }
}

function toggleAttendanceRecording() {

    if (recordingEnabled) {
        // About to turn OFF — need a reason first
        document.getElementById("modalReasonInput").value = "Summer Break";
        document.getElementById("disableReasonModal").classList.add("active");
        lockBodyScroll();
        return;
    }

    // Turning back ON — no reason needed
    applyRecordingState(1, "");
}

function closeDisableReasonModal() {
    document.getElementById("disableReasonModal").classList.remove("active");
    unlockBodyScroll();
}

function confirmDisableRecording() {

    const reason = document.getElementById("modalReasonInput").value.trim()
        || "Attendance recording is currently disabled.";

    closeDisableReasonModal();
    applyRecordingState(0, reason);
}

async function applyRecordingState(newState, reason) {

    try {

        const formData = new FormData();
        formData.append("enabled", newState);
        formData.append("reason", reason);

        const response = await fetch(appUrl('data/settings/recording'), {
            method: "POST",
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || "Failed to update setting.", "error");
            return;
        }

        recordingEnabled = newState === 1;
        renderRecordingStatus(reason);

        showToast(result.message, "success");

    } catch (error) {
        console.error("Toggle Recording Error:", error);
        showToast("An error occurred while updating the setting.", "error");
    }
}

function showToast(message, type = "success") {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.style.background = type === "error" ? "#c62828" : "#14390f";
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

document.addEventListener("DOMContentLoaded", function () {
    loadAttendanceRecordingStatus();
    loadLunchBreak();
    loadDepartmentOptions(currentDeptTab);
    loadRecycleBin(currentRecycleBinTab);
});

/* =========================================================
   RECYCLE BIN
   ========================================================= */

let currentRecycleBinTab = 'teacher';

const RECYCLE_BIN_LABELS = {
    teacher: 'personnel record',
    personnel_on_leave: 'personnel on leave record',
    school_event: 'school schedule change',
    attendance_adjustment: 'attendance adjustment',
    subject: 'subject',
    position: 'position',
    account: 'account'
};

const RECYCLE_BIN_CATEGORY_NAMES = {
    teacher: 'Personnel',
    personnel_on_leave: 'Personnel on Leave',
    school_event: 'School Schedule Changes',
    attendance_adjustment: 'Attendance Adjustments',
    subject: 'Subjects',
    position: 'Positions',
    account: 'Accounts'
};

function switchRecycleBinTab(type, btn) {
    currentRecycleBinTab = type;

    document.querySelectorAll('#recycleBinTabs .dept-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    loadRecycleBin(type);
}

function updateRecycleBinDeleteAllButton(rowCount) {
    const btn = document.getElementById('recycleBinDeleteAllBtn');
    btn.disabled = !rowCount;
    btn.innerHTML = `<i class="fa-solid fa-trash-can"></i> Delete All (${RECYCLE_BIN_CATEGORY_NAMES[currentRecycleBinTab] || ''})`;
}

async function loadRecycleBin(type) {

    const tbody = document.getElementById('recycleBinTableBody');
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;">Loading...</td></tr>`;
    updateRecycleBinDeleteAllButton(0);

    try {

        const response = await fetch(appUrl('data/recycle-bin') + `?type=${encodeURIComponent(type)}`);
        const result = await response.json();

        if (!result.success) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;">Unable to load records.</td></tr>`;
            return;
        }

        const rows = result.rows || [];

        updateRecycleBinDeleteAllButton(rows.length);

        if (rows.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="dept-empty-state">
                        Recycle Bin is empty for this category.
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = rows.map(row => `
            <tr>
                <td>${escapeDeptHTML(row.title)}</td>
                <td>${escapeDeptHTML(row.subtitle)}</td>
                <td>${escapeDeptHTML(row.deleted_at)}</td>
                <td>${escapeDeptHTML(row.deleted_by)}</td>
                <td>
                    <button type="button" class="add-option-btn" style="padding:6px 12px; font-size:12.5px;" onclick="recycleBinAction('${type}', ${row.id}, 'restore')">Restore</button>
                    <button type="button" class="deactivate-option-btn" onclick="confirmRecycleBinPermanentDelete('${type}', ${row.id})">Delete Permanently</button>
                </td>
            </tr>
        `).join('');

    } catch (error) {
        console.error("Failed to load recycle bin:", error);
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;">Failed to load records.</td></tr>`;
    }
}

let recycleBinDeleteType = null;
let recycleBinDeleteId = null;

function confirmRecycleBinPermanentDelete(type, id) {
    recycleBinDeleteType = type;
    recycleBinDeleteId = id;
    document.getElementById('recycleBinDeleteLabel').textContent = RECYCLE_BIN_LABELS[type] || 'record';
    document.getElementById('recycleBinDeleteModal').classList.add('active');
    lockBodyScroll();
}

function closeRecycleBinDeleteModal() {
    recycleBinDeleteType = null;
    recycleBinDeleteId = null;
    document.getElementById('recycleBinDeleteModal').classList.remove('active');
    unlockBodyScroll();
}

function executeRecycleBinPermanentDelete() {
    if (!recycleBinDeleteType || !recycleBinDeleteId) return;
    const type = recycleBinDeleteType;
    const id = recycleBinDeleteId;
    closeRecycleBinDeleteModal();
    recycleBinAction(type, id, 'permanent_delete');
}

function confirmRecycleBinDeleteAll() {
    const btn = document.getElementById('recycleBinDeleteAllBtn');
    if (btn.disabled) return;
    document.getElementById('recycleBinDeleteAllLabel').textContent = RECYCLE_BIN_CATEGORY_NAMES[currentRecycleBinTab] || 'archived';
    document.getElementById('recycleBinDeleteAllModal').classList.add('active');
    lockBodyScroll();
}

function closeRecycleBinDeleteAllModal() {
    document.getElementById('recycleBinDeleteAllModal').classList.remove('active');
    unlockBodyScroll();
}

function executeRecycleBinDeleteAll() {
    const type = currentRecycleBinTab;
    closeRecycleBinDeleteAllModal();
    recycleBinAction(type, 0, 'delete_all');
}

async function recycleBinAction(type, id, action) {

    try {

        const formData = new FormData();
        formData.append("type", type);
        formData.append("id", id);
        formData.append("action", action);

        const response = await fetch(appUrl('data/recycle-bin'), {
            method: "POST",
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || "Action failed.", "error");
            return;
        }

        showToast(result.message, "success");
        loadRecycleBin(currentRecycleBinTab);

    } catch (error) {
        console.error("Recycle Bin Action Error:", error);
        showToast("An error occurred.", "error");
    }
}

/* =========================================================
   ACCOUNT MANAGEMENT
   ========================================================= */

function openDeleteAccountModal(id, name) {
    document.getElementById('deleteAccountId').value = id;
    document.getElementById('deleteAccountName').textContent = name;
    document.getElementById('deleteAccountModal').classList.add('active');
    lockBodyScroll();
}

function closeDeleteAccountModal() {
    document.getElementById('deleteAccountModal').classList.remove('active');
    unlockBodyScroll();
}

/* =========================================================
   KIOSK DEVICES
   ========================================================= */

function openRevokeKioskModal(button) {
    document.getElementById('revokeKioskId').value = button.dataset.kioskId;
    document.getElementById('revokeKioskName').textContent = button.dataset.kioskName;
    document.getElementById('revokeKioskModal').classList.add('active');
    lockBodyScroll();
}

function closeRevokeKioskModal() {
    document.getElementById('revokeKioskModal').classList.remove('active');
    unlockBodyScroll();
}

function openAddAccountModal() {
    document.getElementById('addAccountModal').classList.add('active');
    lockBodyScroll();
}

function closeAddAccountModal() {
    document.getElementById('addAccountModal').classList.remove('active');
    unlockBodyScroll();

    // Always reset password fields back to hidden when the modal closes.
    document.querySelectorAll('#addAccountModal .password-field-wrapper input').forEach(input => {
        input.type = 'password';
    });
    document.querySelectorAll('#addAccountModal .password-toggle-icon').forEach(icon => {
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    });
}

function togglePasswordVisibility(inputId, iconEl) {
    const input = document.getElementById(inputId);
    const isHidden = input.type === 'password';

    input.type = isHidden ? 'text' : 'password';

    iconEl.classList.toggle('fa-eye-slash', !isHidden);
    iconEl.classList.toggle('fa-eye', isHidden);
}

function openResetPasswordModal(id, name, email) {
    document.getElementById('resetPasswordAccountId').value = id;
    document.getElementById('resetPasswordName').textContent = name;
    document.getElementById('resetPasswordEmail').textContent = email;
    document.getElementById('resetPasswordModal').classList.add('active');
    lockBodyScroll();
}

function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.remove('active');
    unlockBodyScroll();

    // Always reset password fields back to empty/hidden when the modal closes.
    document.querySelectorAll('#resetPasswordModal .password-field-wrapper input').forEach(input => {
        input.value = '';
        input.type = 'password';
    });
    document.querySelectorAll('#resetPasswordModal .password-toggle-icon').forEach(icon => {
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    });
}


async function loadLunchBreak() {
    try {
        const response = await fetch(appUrl('data/recording-status'));
        const result = await response.json();

        if (result.success) {
           document.getElementById("lunchOutInput").value = (result.lunch_out || "12:15:00").substring(0,5);
            document.getElementById("lunchInInput").value = (result.lunch_in || "13:00:00").substring(0,5);

            const body = document.getElementById("scheduleBreakRows");
            body.innerHTML = "";
            (result.schedule_breaks || []).forEach(addScheduleBreakRow);
        }
    } catch (error) {
        console.error("Failed to load lunch break:", error);
    }
}

function addScheduleBreakRow(entry) {

    const e = (entry && entry.time_in) ? entry : { time_in: "", time_out: "", break_start: "", break_end: "" };
    const cell = (name, value) =>
        '<td><input type="time" data-field="' + name + '" value="' + (value || "").substring(0, 5) +
        '" style="padding:8px 10px; border:1px solid #e6dcc4; border-radius:8px;"></td>';

    const tr = document.createElement("tr");
    tr.innerHTML =
        cell("time_in", e.time_in) + cell("time_out", e.time_out) +
        cell("break_start", e.break_start) + cell("break_end", e.break_end) +
        '<td><button type="button" class="deactivate-option-btn" onclick="openScheduleBreakRemoveModal(this)">Remove</button></td>';

    document.getElementById("scheduleBreakRows").appendChild(tr);
}

let scheduleBreakRowToRemove = null;

function openScheduleBreakRemoveModal(button) {
    scheduleBreakRowToRemove = button.closest("tr");
    document.getElementById("scheduleBreakRemoveModal").classList.add("active");
    lockBodyScroll();
}

function closeScheduleBreakRemoveModal() {
    scheduleBreakRowToRemove = null;
    document.getElementById("scheduleBreakRemoveModal").classList.remove("active");
    unlockBodyScroll();
}

function confirmRemoveScheduleBreak() {
    if (scheduleBreakRowToRemove) {
        scheduleBreakRowToRemove.remove();
    }
    closeScheduleBreakRemoveModal();
}

function collectScheduleBreaks() {

    const rows = [];

    document.querySelectorAll("#scheduleBreakRows tr").forEach(tr => {
        const get = f => tr.querySelector('[data-field="' + f + '"]');
        rows.push({
            time_in: get("time_in").value,
            time_out: get("time_out").value,
            break_start: get("break_start").value,
            break_end: get("break_end").value
        });
    });

    return rows;
}

async function saveLunchBreak() {

    const lunchOut = document.getElementById("lunchOutInput").value;
    const lunchIn = document.getElementById("lunchInInput").value;

    if (!lunchOut || !lunchIn) {
        showToast("Please set both lunch times.", "error");
        return;
    }

    const scheduleBreaks = collectScheduleBreaks();

    if (scheduleBreaks.some(r => !r.time_in || !r.time_out || !r.break_start || !r.break_end)) {
        showToast("Every schedule break needs a Time In, Time Out, Break Start and Break End.", "error");
        return;
    }

    try {

        const formData = new FormData();
        formData.append("lunch_out", lunchOut);
        formData.append("lunch_in", lunchIn);
        formData.append("schedule_breaks", JSON.stringify(scheduleBreaks));

        const response = await fetch(appUrl('data/settings/lunch-break'), {
            method: "POST",
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || "Failed to save.", "error");
            return;
        }

        showToast("Lunch break updated successfully.");

    } catch (error) {
        console.error("Save Lunch Break Error:", error);
        showToast("An error occurred.", "error");
    }
}

/* =========================================================
   MANAGE SUBJECTS & POSITIONS
   ========================================================= */

const PERSONNEL_TYPE_LABELS = {
    'Teaching': 'Teaching Staff',
    'Non-Teaching': 'Non-Teaching Staff / School Administrator'
};

let currentDeptTab = 'Teaching';
let optionIdToDeactivate = null;
let optionNameToDeactivate = null;

function switchDeptTab(personnelType) {

    currentDeptTab = personnelType;

    document.getElementById('deptTabTeaching').classList.toggle('active', personnelType === 'Teaching');
    document.getElementById('deptTabNonTeaching').classList.toggle('active', personnelType === 'Non-Teaching');

    loadDepartmentOptions(personnelType);
}

async function loadDepartmentOptions(personnelType) {

    const tbody = document.getElementById('departmentOptionsTableBody');
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>`;

    try {

        // Same endpoint/columns the Add/Edit Personnel form's Position
        // dropdown reads from — option_type is always 'Position' here;
        // Subjects are never fetched or shown in this management UI.
        const response = await fetch(appUrl('data/department-options') + `?type=Position&personnel_type=${encodeURIComponent(personnelType)}`);
        const result = await response.json();

        if (!result.success) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">Unable to load positions.</td></tr>`;
            return;
        }

        const options = result.options || [];

        if (options.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="dept-empty-state">
                        No ${PERSONNEL_TYPE_LABELS[personnelType] || personnelType} positions added yet.
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = options.map(opt => `
            <tr>
                <td>${escapeDeptHTML(opt.option_name)}</td>
                <td>${opt.personnel_type ? escapeDeptHTML(PERSONNEL_TYPE_LABELS[opt.personnel_type] || opt.personnel_type) : '—'}</td>
                <td><span class="status-badge enabled" style="padding:4px 12px; font-size:12px;">${escapeDeptHTML(opt.status)}</span></td>
                <td>
                    <button type="button" class="deactivate-option-btn" onclick="openDeactivateOptionModal(${opt.id}, '${escapeDeptHTML(opt.option_name).replace(/'/g, "\\'")}')">
                        Deactivate
                    </button>
                </td>
            </tr>
        `).join('');

    } catch (error) {
        console.error("Failed to load department options:", error);
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">Failed to load positions.</td></tr>`;
    }
}

function escapeDeptHTML(value) {
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
}

function openAddOptionModal() {
    document.getElementById('addOptionNameInput').value = '';
    document.getElementById('addOptionPersonnelTypeSelect').value = currentDeptTab;
    document.getElementById('addOptionModal').classList.add('active');
    lockBodyScroll();
}

function closeAddOptionModal() {
    document.getElementById('addOptionModal').classList.remove('active');
    unlockBodyScroll();
}

async function saveNewOption() {

    const name = document.getElementById('addOptionNameInput').value.trim();
    const personnelType = document.getElementById('addOptionPersonnelTypeSelect').value;

    if (!name) {
        showToast("Please enter a name.", "error");
        return;
    }

    if (!personnelType) {
        showToast("Please select a Personnel Type for this position.", "error");
        return;
    }

    try {

        const formData = new FormData();
        formData.append("option_type", "Position");
        formData.append("option_name", name);
        formData.append("personnel_type", personnelType);

        const response = await fetch(appUrl('data/department-options'), {
            method: "POST",
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || "Failed to save.", "error");
            return;
        }

        showToast(result.message, "success");
        closeAddOptionModal();

        if (personnelType === currentDeptTab) {
            loadDepartmentOptions(currentDeptTab);
        }

    } catch (error) {
        console.error("Save Option Error:", error);
        showToast("An error occurred while saving.", "error");
    }
}

function openDeactivateOptionModal(id, name) {
    optionIdToDeactivate = id;
    optionNameToDeactivate = name;
    document.getElementById('deactivateOptionName').textContent = name;
    document.getElementById('deactivateOptionModal').classList.add('active');
    lockBodyScroll();
}

function closeDeactivateOptionModal() {
    optionIdToDeactivate = null;
    optionNameToDeactivate = null;
    document.getElementById('deactivateOptionModal').classList.remove('active');
    unlockBodyScroll();
}

async function confirmDeactivateOption() {

    if (!optionIdToDeactivate) return;

    try {

        const formData = new FormData();
        formData.append("id", optionIdToDeactivate);

        const response = await fetch(appUrl('data/department-options/deactivate'), {
            method: "POST",
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || "Failed to deactivate.", "error");
            closeDeactivateOptionModal();
            return;
        }

        showToast("Moved to the Recycle Bin.", "success");
        closeDeactivateOptionModal();
        loadDepartmentOptions(currentDeptTab);

    } catch (error) {
        console.error("Deactivate Option Error:", error);
        showToast("An error occurred.", "error");
        closeDeactivateOptionModal();
    }
}

/* =========================================================
   DATABASE BACKUP
   The browser only asks the protected endpoints; the dump is made
   on the server and downloads go through download-database-backup.php.
   ========================================================= */

async function loadDatabaseBackups() {

    const tbody = document.getElementById('databaseBackupTableBody');
    if (!tbody) return;

    try {

        const response = await fetch(appUrl('data/backups'));
        const result = await response.json();

        if (!result.success) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Unable to load the backup list.</td></tr>';
            return;
        }

        const backups = result.backups || [];

        if (backups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#a89a6e; padding:24px;">No database backups have been created yet.</td></tr>';
            return;
        }

        tbody.innerHTML = backups.map(b => `
            <tr>
                <td class="backup-filename">${escapeDeptHTML(b.filename)}</td>
                <td>${escapeDeptHTML(b.created_at)}</td>
                <td>${escapeDeptHTML(b.size_display)}</td>
                <td>
                    <a class="add-option-btn" style="padding:6px 12px; font-size:12.5px;"
                       href="${appUrl('data/backups/download')}?file=${encodeURIComponent(b.filename)}">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                </td>
            </tr>
        `).join('');

    } catch (error) {
        console.error('Load Database Backups Error:', error);
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Unable to load the backup list.</td></tr>';
    }
}

async function createDatabaseBackup() {

    const btn = document.getElementById('createBackupBtn');
    if (!btn || btn.disabled) return;

    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating backup...';

    try {

        const response = await fetch(appUrl('data/backups'), { method: 'POST' });
        const result = await response.json();

        if (!result.success) {
            showToast(result.message || 'The database backup could not be created.', 'error');
            return;
        }

        showToast(result.message + (result.backup ? ' (' + result.backup.filename + ')' : ''), 'success');
        loadDatabaseBackups();

    } catch (error) {
        console.error('Create Database Backup Error:', error);
        showToast('An error occurred while creating the database backup.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

document.addEventListener('DOMContentLoaded', loadDatabaseBackups);


/* =========================================================
   AUDIT LOG (new): who changed what, newest first.
   ========================================================= */

function auditActionLabel(action) {
    return String(action || '').replace(/[._]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

async function loadAuditLog() {
    const tbody = document.getElementById('auditLogTableBody');
    if (!tbody) return;

    try {
        const response = await fetch(appUrl('data/audit-log') + '?limit=200');
        const result = await response.json();

        if (!result.success) throw new Error(result.message || 'failed');

        if (!result.entries.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No activity recorded yet.</td></tr>';
            return;
        }

        tbody.innerHTML = result.entries.map(e => {
            let details = '';
            try {
                const d = e.details ? JSON.parse(e.details) : null;
                if (d) details = Object.entries(d).map(([k, v]) => k + ': ' + (typeof v === 'object' ? JSON.stringify(v) : v)).join(', ');
            } catch (err) {
                details = e.details || '';
            }
            const subject = e.subject_type ? (e.subject_type + (e.subject_id ? ' #' + e.subject_id : '')) : '';

            return `<tr>
                <td style="white-space:nowrap;">${escapeHtml(e.created_at)}</td>
                <td>${escapeHtml(e.actor)}</td>
                <td>${escapeHtml(auditActionLabel(e.action))}</td>
                <td>${escapeHtml(subject)}</td>
                <td class="audit-details">${escapeHtml(details.length > 220 ? details.slice(0, 220) + '…' : details)}</td>
            </tr>`;
        }).join('');

    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Unable to load the audit log.</td></tr>';
    }
}

document.addEventListener('DOMContentLoaded', loadAuditLog);
