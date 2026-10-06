/* =========================================================
   PERSONNEL MANAGEMENT (Super Admin)
   Moved from the inline scripts of superadmin/super-admin.php.
   Server data comes from window.PAGE (set by the Blade view):
   paidBreakConfig, teachers, allTeachers, schedulePicker.
   ========================================================= */

/* Paid break of an assigned schedule (twin of dtrBreakForSchedule in dtr/attendance-rules.php). */
const PAID_BREAK_CONFIG = window.PAGE.paidBreakConfig;

function paidBreakFor(timeIn, timeOut) {

    if (!timeIn || !timeOut) return null;

    const norm = t => (t.length === 5 ? t + ':00' : t);
    const tin = norm(timeIn), tout = norm(timeOut);

    const hit = (PAID_BREAK_CONFIG.entries || []).find(e => e.time_in === tin && e.time_out === tout);
    if (hit) return { start: hit.break_start, end: hit.break_end };

    // ordinary day schedule (starts before noon, ends the same day): shared lunch break
    if (tin < '12:00:00' && tout >= tin && PAID_BREAK_CONFIG.lunch_out && PAID_BREAK_CONFIG.lunch_in) {
        return { start: PAID_BREAK_CONFIG.lunch_out, end: PAID_BREAK_CONFIG.lunch_in };
    }

    return null;
}

function formatBreakTime(t) {
    const h = parseInt(t.substring(0, 2), 10), m = t.substring(3, 5);
    return ((h % 12) || 12) + ':' + m + ' ' + (h >= 12 ? 'PM' : 'AM');
}

function updatePaidBreakNotes() {

    ['add', 'edit'].forEach(prefix => {
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach(day => {

            const note = document.getElementById(prefix + '_' + day + '_break');
            const tin = document.getElementById(prefix + '_' + day + '_in');
            const tout = document.getElementById(prefix + '_' + day + '_out');
            if (!note || !tin || !tout) return;

            const b = (tin.disabled || tout.disabled) ? null : paidBreakFor(tin.value, tout.value);
            note.textContent = b ? ('Paid break: ' + formatBreakTime(b.start) + ' - ' + formatBreakTime(b.end)) : '';
        });
    });
}

document.addEventListener('input', function (e) {
    if (e.target && /^(add|edit)_[a-z]+_(in|out)$/.test(e.target.id || '')) updatePaidBreakNotes();
});
document.addEventListener('DOMContentLoaded', updatePaidBreakNotes);


// lockBodyScroll()/unlockBodyScroll() are now shared (includes/sidebar.php).

// =====================================================
// TEACHER DATA
// =====================================================

const teachersData = window.PAGE.teachers;

// Full, unpaginated teacher list — used only by "Print All Teacher Badges"
// so it isn't limited to whichever page you're currently viewing.
const allTeachersData = window.PAGE.allTeachers;


// =====================================================
// PAGE LOAD
// =====================================================

document.addEventListener("DOMContentLoaded", function () {

    const field = document.getElementById("generatedId");

    if (field && field.value === "") {
        field.placeholder = "Will be generated teacher ID automatically";
    }

});


// =====================================================
// GENERATE BARCODE DISPLAY
// =====================================================

document.querySelectorAll('.barcode-svg').forEach(svg => {

    JsBarcode(
        svg,
        svg.dataset.barcode,
        {
            format: 'CODE39',
            displayValue: true,
            fontSize: 12,
            height: 40,
            width: 1.2
        }
    );

});


// =====================================================
// EDIT BUTTON
// =====================================================

document.querySelectorAll('.edit-button').forEach(button => {

    button.addEventListener('click', function () {

        const teacherId = parseInt(this.dataset.id);

        const teacher = teachersData.find(t => parseInt(t.id) === teacherId);

        if (!teacher) {
            showToast("Personnel information not found.", "error");
            return;
        }

        openEditModal(teacher);

    });

});


// =====================================================
// PHOTO PREVIEW
// =====================================================

const modalPhotoInput = document.getElementById('modalPhotoInput');

if (modalPhotoInput) {

    modalPhotoInput.addEventListener('change', function () {

        const preview = document.getElementById('modalPhotoPreview');
        const text = document.getElementById('modalPhotoText');
        const file = this.files[0];

        if (file) {

            const reader = new FileReader();

            reader.onload = function (e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
                text.style.display = 'none';
            };

            reader.readAsDataURL(file);

        } else {

            preview.style.display = 'none';
            text.style.display = 'block';

        }

    });
}


// =====================================================
// EDIT MODAL
// =====================================================

async function openEditModal(teacher) {

    document.getElementById('modalId').value = teacher.id;
    document.getElementById('modalTeacherIdNumber').value = teacher.id_number;
    document.getElementById('modalTeacherName').value = teacher.fullname;
    document.getElementById('modalAcademicStatus').value = teacher.academic_status;
    document.getElementById('modalEmploymentType').value = teacher.employment_type;
    document.getElementById('modalBarcode').value = teacher.barcode;

    // Load the correct Position list BEFORE trying to select this
    // teacher's actual saved value, otherwise the option won't exist yet.
    await populateDeptOptions('edit', teacher.academic_status, teacher.department);
    document.getElementById('modalTeacherDeptSelect').value = teacher.department;

    const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    days.forEach(day => {

        const checkbox = document.getElementById('edit_' + day + '_enabled');
        const timeIn = document.getElementById('edit_' + day + '_in');
        const timeOut = document.getElementById('edit_' + day + '_out');

        if (checkbox) { checkbox.checked = false; }
        if (timeIn) { timeIn.value = ''; timeIn.disabled = true; }
        if (timeOut) { timeOut.value = ''; timeOut.disabled = true; }

    });

    const schedules = teacher.schedules || [];

    schedules.forEach(schedule => {

        const day = schedule.day.toLowerCase();

        const checkbox = document.getElementById('edit_' + day + '_enabled');
        const timeIn = document.getElementById('edit_' + day + '_in');
        const timeOut = document.getElementById('edit_' + day + '_out');

        if (checkbox) { checkbox.checked = true; }

        if (timeIn) {
            timeIn.value = schedule.time_in.substring(0, 5);
            timeIn.disabled = false;
        }

        if (timeOut) {
            timeOut.value = schedule.time_out.substring(0, 5);
            timeOut.disabled = false;
        }

    });

    updatePaidBreakNotes();

    const preview = document.getElementById('modalPhotoPreview');
    const text = document.getElementById('modalPhotoText');
    const photoInput = document.getElementById('modalPhotoInput');
    const photo = teacher.photo || '';

    if (photo && photo.trim() !== '') {

        preview.src = appUrl('photos/' + encodeURIComponent(photo));
        preview.style.display = 'block';
        text.style.display = 'none';

    } else {

        preview.src = appUrl('img/logo.png');
        preview.style.display = 'block';
        text.style.display = 'none';

    }

    if (photoInput) { photoInput.value = ''; }

    document.getElementById('editModal').classList.add('active');
    lockBodyScroll();

}


function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    unlockBodyScroll();
}


// =====================================================
// DELETE MODAL
// =====================================================

function openDeleteModal(id, name) {

    document.getElementById('deleteTeacherId').value = id;
    document.getElementById('deleteTeacherName').textContent = name;

    document.getElementById('deleteModal').classList.add('active');
    lockBodyScroll();

}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    unlockBodyScroll();
}


// =====================================================
// REGENERATE BARCODE MODAL
// =====================================================

let teacherIdToRegenerate = null;

function openRegenerateModal(id, name) {

    teacherIdToRegenerate = id;

    document.getElementById('regenerateTeacherName').textContent = name;
    document.getElementById('regenerateBarcodeModal').classList.add('active');
    lockBodyScroll();

}

function closeRegenerateModal() {

    teacherIdToRegenerate = null;

    document.getElementById('regenerateBarcodeModal').classList.remove('active');
    unlockBodyScroll();

}

function confirmRegenerateBarcode() {

    if (!teacherIdToRegenerate) { return; }

    const form = document.getElementById('regenerateForm-' + teacherIdToRegenerate);

    if (form) {
        // Page reloads after this; the success/error toast is shown
        // automatically via the PHP session message on the next page load.
        form.submit();
    }

}


// =====================================================
// TOAST
// =====================================================

function showToast(message, type = "success") {

    const toast = document.getElementById('toast');

    toast.textContent = message;
    toast.style.background = (type === "error") ? "#c62828" : "#14390f";

    toast.classList.add('show');

    setTimeout(() => { toast.classList.remove('show'); }, 3000);

}

// =====================================================
// ERROR MODAL (replaces raw browser alert() for
// validation/failure messages)
// =====================================================

function showErrorModal(message) {
    document.getElementById('appErrorModalMessage').textContent = message || 'An error occurred.';
    document.getElementById('appErrorModal').classList.add('active');
    lockBodyScroll();
}

function closeErrorModal() {
    document.getElementById('appErrorModal').classList.remove('active');
    unlockBodyScroll();
}

// =====================================================
// PRINT INDIVIDUAL BADGE
// =====================================================

function printTeacherBadge(button) {

    const idNumber = button.dataset.idnumber;
    const name = button.dataset.name;
    const department = button.dataset.department;
    const academic = button.dataset.academic;
    const employment = button.dataset.employment;
    const barcode = button.dataset.barcode;
    const photo = button.dataset.photo;

    const photoPath = photo
        ? appUrl('photos/' + encodeURIComponent(photo))
        : appUrl('img/logo.png');

    const content = `

<!DOCTYPE html>

<html>

<head>

<title>Print Badge</title>

<style>

* {
    box-sizing: border-box;
}

/* Screen view of this staging window — the actual printed
   paper background is forced white separately below. */
body {
    margin: 0;
    padding: 20px;
    font-family: Arial, sans-serif;
    background: #f5f0e1;
}

.badge {
    width: 340px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(20, 57, 15, 0.15);
    border: 1px solid #e6dcc4;
    break-inside: avoid;
    page-break-inside: avoid;
}

.badge-header {
    background: #14390f;
    color: white;
    padding: 12px 16px;
    text-align: center;
}

.badge-header .school-name {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.badge-header .school-location {
    font-size: 9px;
    color: #e8a317;
    margin-top: 2px;
    letter-spacing: 0.3px;
}

.photo-zone {
    background: linear-gradient(to bottom, #ffffff 85%, #fffdf8 100%);
    padding: 22px 20px 16px;
    text-align: center;
}

.photo-frame {
    width: 150px;
    height: 150px;
    margin: 0 auto;
    border-radius: 12px;
    padding: 3px;
    background: linear-gradient(135deg, #e8a317, #f2c94c);
}

.photo-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 10px;
    display: block;
}

.content-zone {
    position: relative;
    padding: 18px 22px 20px;
    text-align: center;
    background: linear-gradient(
        to bottom,
        #fffdf8 0%,
        #fbf3e6 25%,
        #f3e9d4 50%,
        #e9dfc4 75%,
        #d9dfc0 100%
    );
    overflow: hidden;
}

.content-zone::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(
        160deg,
        transparent 0%,
        transparent 30%,
        rgba(74, 124, 63, 0.18) 65%,
        rgba(232, 163, 23, 0.28) 100%
    );
    z-index: 0;
}

.content-inner {
    position: relative;
    z-index: 1;
}

.badge-name {
    font-size: 21px;
    font-weight: 700;
    color: #14390f;
    margin: 0 0 12px;
    line-height: 1.2;
}

.badge-info {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 16px;
}

.info-pill {
    background: #f1ede0;
    border: 1px solid #e6dcc4;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 11px;
    text-align: left;
}

.info-pill .info-label {
    color: #8a7d5c;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    display: block;
}

.info-pill .info-value {
    color: #14390f;
    font-weight: 700;
    font-size: 13px;
}

.gold-divider {
    height: 2px;
    background: linear-gradient(90deg, transparent, #e8a317, transparent);
    margin: 0 0 14px;
}

.barcode-wrap {
    text-align: center;
}

/* ===== PRINT ===== */

@media print {

    @page {
        margin: 10mm;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Bond paper stays white. Only the badge itself keeps its
       colored design — see .badge / .badge-header / .content-zone
       rules above, which are untouched and still apply during print. */
    html, body {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .badge {
        break-inside: avoid;
        page-break-inside: avoid;
        box-shadow: none;
    }
}

</style>

</head>

<body>

<div class="badge">

    <div class="badge-header">
        <div class="school-name">San Jose Community High School</div>
        <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
    </div>

    <div class="photo-zone">
        <div class="photo-frame">
            <img src="${photoPath}" alt="Personnel Photo" id="badgePhotoImg">
        </div>
    </div>

    <div class="content-zone">
        <div class="content-inner">

            <h2 class="badge-name">${name}</h2>

            <div class="badge-info">
                <div class="info-pill">
                    <span class="info-label">ID Number</span>
                    <span class="info-value">${idNumber}</span>
                </div>
                <div class="info-pill">
                    <span class="info-label">Department</span>
                    <span class="info-value">${department}</span>
                </div>
            </div>

            <div class="gold-divider"></div>

            <div class="barcode-wrap">
                <svg id="print-barcode"></svg>
            </div>

        </div>
    </div>

</div>

<script src="${appUrl('vendor/jsbarcode/JsBarcode.all.min.js')}"><\/script>

<script>

JsBarcode(
    '#print-barcode',
    '${barcode}',
    {
        format:'CODE39',
        displayValue:true,
        fontSize:14,
        height:70,
        width:1.4,
        margin: 0,
        background: "transparent"
    }
);

// Explicitly wait for the teacher photo (or logo fallback) to finish
// loading before opening the print dialog, so it can never race ahead
// of the image and print a blank photo area.
function waitForImagesThenPrint() {

    const img = document.getElementById('badgePhotoImg');

    if (img.complete) {
        window.print();
        return;
    }

    img.addEventListener('load', () => window.print());
    img.addEventListener('error', () => window.print());
}

window.onload = waitForImagesThenPrint;

<\/script>

</body>

</html>

`;

    printHTMLDocument(content);

}


// =====================================================
// PRINT ALL BADGES
// =====================================================

function printAllTeacherBadges() {

    const teachers = allTeachersData;

    let badgeHTML = '';

    teachers.forEach((teacher, index) => {

        const photoPath = teacher.photo
            ? appUrl('photos/' + encodeURIComponent(teacher.photo))
            : appUrl('img/logo.png');

        badgeHTML += `

<div class="badge">

    <div class="badge-header">
        <div class="school-name">San Jose Community High School</div>
        <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
    </div>

    <div class="photo-zone">
        <div class="photo-frame">
            <img src="${photoPath}" alt="Personnel Photo" class="badgePhotoImg">
        </div>
    </div>

    <div class="content-zone">
        <div class="content-inner">

            <h2 class="badge-name">${teacher.fullname}</h2>

            <div class="badge-info">
                <div class="info-pill">
                    <span class="info-label">ID Number</span>
                    <span class="info-value">${teacher.id_number}</span>
                </div>
                <div class="info-pill">
                    <span class="info-label">Department</span>
                    <span class="info-value">${teacher.department}</span>
                </div>
            </div>

            <div class="gold-divider"></div>

            <div class="barcode-wrap">
                <svg id="barcode-all-${index}"></svg>
            </div>

        </div>
    </div>

</div>

`;

    });


    const content = `

<!DOCTYPE html>

<html>

<head>

<title>Print All Personnel Badges</title>

<style>

* {
    box-sizing: border-box;
}

/* Screen view of this staging window — the actual printed
   paper background is forced white separately below. */
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f5f0e1;
    padding: 20px;
}

.container {
    display: block;
    text-align: center;
}

/* ===== SAME BADGE DESIGN AS THE INDIVIDUAL PRINT — REAL SIZE ===== */

.badge {
    display: inline-block;
    vertical-align: top;
    width: 340px;
    background: #ffffff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 16px rgba(20, 57, 15, 0.12);
    border: 1px solid #e6dcc4;
    box-sizing: border-box;
    break-inside: avoid;
    page-break-inside: avoid;
    margin: 0 8px 16px;
}

.badge-header {
    background: #14390f;
    color: white;
    padding: 12px 16px;
    text-align: center;
}

.badge-header .school-name {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.badge-header .school-location {
    font-size: 9px;
    color: #e8a317;
    margin-top: 2px;
    letter-spacing: 0.3px;
}

.photo-zone {
    background: linear-gradient(to bottom, #ffffff 85%, #fffdf8 100%);
    padding: 22px 20px 16px;
    text-align: center;
}

.photo-frame {
    width: 150px;
    height: 150px;
    margin: 0 auto;
    border-radius: 12px;
    padding: 3px;
    background: linear-gradient(135deg, #e8a317, #f2c94c);
}

.photo-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 10px;
    display: block;
}

.content-zone {
    position: relative;
    padding: 18px 22px 20px;
    text-align: center;
    background: linear-gradient(
        to bottom,
        #fffdf8 0%,
        #fbf3e6 25%,
        #f3e9d4 50%,
        #e9dfc4 75%,
        #d9dfc0 100%
    );
    overflow: hidden;
}

.content-inner {
    position: relative;
    z-index: 1;
}

.badge-name {
    font-size: 21px;
    font-weight: 700;
    color: #14390f;
    margin: 0 0 12px;
    line-height: 1.2;
}

.badge-info {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 16px;
}

.info-pill {
    background: #f1ede0;
    border: 1px solid #e6dcc4;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 11px;
    text-align: left;
}

.info-pill .info-label {
    color: #8a7d5c;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    display: block;
}

.info-pill .info-value {
    color: #14390f;
    font-weight: 700;
    font-size: 13px;
}

.gold-divider {
    height: 2px;
    background: linear-gradient(90deg, transparent, #e8a317, transparent);
    margin: 0 0 14px;
}

.barcode-wrap {
    text-align: center;
}

/* ===== PRINT ===== */

@media print {

    @page {
        size: A4 portrait;
        margin: 6mm;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Bond paper stays white. Only each badge keeps its
       colored design — see .badge / .badge-header / .content-zone
       rules above, which are untouched and still apply during print. */
    html, body {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .badge {
        break-inside: avoid;
        page-break-inside: avoid;
        box-shadow: none;
    }
}

</style>

</head>

<body>

<div class="container">
${badgeHTML}
</div>

<script src="${appUrl('vendor/jsbarcode/JsBarcode.all.min.js')}"><\/script>

<script>

const teacherBarcodes = ${JSON.stringify(teachers.map(t => t.barcode))};

teacherBarcodes.forEach((barcode, index) => {

    JsBarcode(
        '#barcode-all-' + index,
        barcode,
        {
            format: 'CODE39',
            displayValue: true,
            fontSize: 14,
            height: 70,
            width: 1.4,
            margin: 0,
            background: "transparent"
        }
    );

});

// Explicitly wait for every teacher photo (or logo fallback) to finish
// loading before opening the print dialog, so photos can never race
// ahead of the images and print blank.
function waitForImagesThenPrint() {

    const images = Array.from(document.querySelectorAll('.badgePhotoImg'));

    const pending = images.filter(img => !img.complete);

    if (pending.length === 0) {
        window.print();
        return;
    }

    let remaining = pending.length;

    function tryPrint() {
        remaining--;
        if (remaining <= 0) {
            window.print();
        }
    }

    pending.forEach(img => {
        img.addEventListener('load', tryPrint);
        img.addEventListener('error', tryPrint);
    });
}

window.onload = waitForImagesThenPrint;

<\/script>

</body>

</html>

`;

    printHTMLDocument(content);

}


// =====================================================
// NAME AUTO CAPITALIZATION
// =====================================================

const nameInput = document.querySelector("input[name='name']");

if (nameInput) {

    nameInput.addEventListener("input", function () {
        this.value = this.value.toLowerCase().replace(/\b\w/g, char => char.toUpperCase());
    });

}


// =====================================================
// ADD SCHEDULE TOGGLE
// =====================================================

function toggleSchedule(day) {

    const checkbox = document.querySelector(`input[name="schedule[${day}][enabled]"]`);
    const timeIn = document.getElementById('add_' + day + '_in');
    const timeOut = document.getElementById('add_' + day + '_out');

    if (!checkbox) { return; }

    timeIn.disabled = !checkbox.checked;
    timeOut.disabled = !checkbox.checked;

}


// =====================================================
// EDIT SCHEDULE TOGGLE
// =====================================================

function toggleEditSchedule(day) {

    const checkbox = document.getElementById('edit_' + day + '_enabled');
    const timeIn = document.getElementById('edit_' + day + '_in');
    const timeOut = document.getElementById('edit_' + day + '_out');

    if (!checkbox) { return; }

    timeIn.disabled = !checkbox.checked;
    timeOut.disabled = !checkbox.checked;

}


// =====================================================
// ACADEMIC STATUS -> SUBJECT / POSITION DROPDOWN
// =====================================================

function handleAcademicStatusChange(context) {

    const statusSelect = document.getElementById(
        context === 'add' ? 'addAcademicStatusSelect' : 'modalAcademicStatus'
    );

    populateDeptOptions(context, statusSelect.value);
}

async function populateDeptOptions(context, academicStatus, currentValue) {

    const selectId = context === 'add' ? 'addTeacherDeptSelect' : 'modalTeacherDeptSelect';
    const labelId = context === 'add' ? 'addTeacherDeptLabel' : 'modalTeacherDeptLabel';

    const select = document.getElementById(selectId);
    const label = document.getElementById(labelId);

    label.textContent = 'Position';

    if (!academicStatus) {
        select.innerHTML = '<option value="">Select Personnel Type first</option>';
        return;
    }

    // Both Personnel Types now select from Position records — which
    // ones are offered is filtered by department_options.personnel_type
    // ('Teaching' for Teaching Staff, 'Non-Teaching' for Non-Teaching
    // Staff / School Administrator). Subjects are no longer offered
    // here, but existing Subject records are left untouched in Settings.
    const personnelType = academicStatus === 'Academic' ? 'Teaching' : 'Non-Teaching';

    select.innerHTML = '<option value="">Loading...</option>';

    try {

        const response = await fetch(appUrl("data/department-options") + "?type=Position&personnel_type=" + encodeURIComponent(personnelType));
        const result = await response.json();

        const options = (result.success && result.options) ? result.options : [];

        let optionsHTML = options.map(opt =>
            `<option value="${opt.option_name.replace(/"/g, '&quot;')}">${opt.option_name}</option>`
        ).join('');

        // If this teacher's currently saved value isn't in the filtered
        // list (e.g. an existing Teaching Staff record still holding a
        // legacy Subject name from before this change), keep it as a
        // selectable option instead of silently dropping it — it only
        // changes if the user deliberately picks something else.
        if (currentValue && !options.some(opt => opt.option_name === currentValue)) {
            optionsHTML += `<option value="${currentValue.replace(/"/g, '&quot;')}">${currentValue} (current)</option>`;
        }

        if (!optionsHTML) {
            select.innerHTML = `<option value="">No positions available — add one in Settings</option>`;
            return;
        }

        select.innerHTML = '<option value="">Select Position</option>' + optionsHTML;

    } catch (error) {
        console.error("Failed to load department options:", error);
        select.innerHTML = '<option value="">Unable to load options</option>';
    }
}



// =====================================================
// ADD LEAVE MODAL
// =====================================================

function openLeaveModal() {
    document.getElementById('leaveModal').classList.add('active');
    lockBodyScroll();
}

function closeLeaveModal() {
    document.getElementById('leaveModal').classList.remove('active');
    unlockBodyScroll();
}

// Only fires once the browser has actually accepted the submission (native
// required-field validation already passed) — disabling here can't get
// stuck on a blocked/invalid submit, since onsubmit never runs for one.
function disableLeaveSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
    }
}

/* =========================================================
   SCHOOL EVENTS
   ========================================================= */

let schoolEventsById = {};

async function loadSchoolHolidays() {

    const tbody = document.getElementById("schoolHolidayTableBody");
    const count = document.getElementById("holidayCount");

    if (!tbody) { return; }

    try {

        const response = await fetch(appUrl("data/school-events"));
        const result = await response.json();

        if (!result.success) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;">Unable to load schedule changes.</td></tr>`;
            return;
        }

        const events = result.events || [];

        schoolEventsById = {};
        events.forEach(event => { schoolEventsById[event.id] = event; });

        if (count) { count.textContent = events.length; }

        if (events.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align:center; padding:30px;">
                        <strong>No School Schedule Changes</strong>
                        <p style="color:#a89a6e;">No schedule changes have been added yet.</p>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = events.map(event => {

            const fromDate = new Date(event.date_from + "T00:00:00");
            const toDate = new Date(event.date_to + "T00:00:00");

            const formattedFrom = fromDate.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
            const formattedTo = toDate.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });

            const dateDisplay = event.date_from === event.date_to
                ? formattedFrom
                : `${formattedFrom} - ${formattedTo}`;

            let durationDisplay = escapeHolidayHTML(event.duration || "Whole Day");
            if (event.duration === "Half Day" && event.start_time && event.end_time) {
                durationDisplay += `<br><small>${escapeHolidayHTML(event.start_time)} - ${escapeHolidayHTML(event.end_time)}</small>`;
            }

            const remarkLine = event.remark
                ? `<br><small style="color:#8a7d5c;">${escapeHolidayHTML(event.remark)}</small>`
                : "";

            const workerIds = event.worker_ids || [];
            const workerCount = workerIds.length;

            let workerLine = "";
            if (workerCount > 0) {
                const workers = workerIds
                    .map(id => holidayPersonnelAll.find(p => Number(p.id) === Number(id)))
                    .filter(Boolean)
                    .sort((a, b) => a.fullname.localeCompare(b.fullname));

                const workerItems = workers
                    .map(p => `<li>${escapeHolidayHTML(p.fullname)}${p.id_number ? ' — ' + escapeHolidayHTML(p.id_number) : ''}</li>`)
                    .join('');

                workerLine = `<br><details class="schedule-change-workers"><summary>${workerCount} personnel scheduled to work</summary><ul>${workerItems}</ul></details>`;
            }

            return `
                <tr>
                    <td><strong>${escapeHolidayHTML(event.event_name)}</strong>${remarkLine}${workerLine}</td>
                    <td>${escapeHolidayHTML(event.event_type)}</td>
                    <td>${dateDisplay}</td>
                    <td>${durationDisplay}</td>
                    <td>${Number(event.included_in_total_hours) === 1 ? "Yes" : "No"}</td>
                    <td><span class="pill pill-active">${escapeHolidayHTML(event.status)}</span></td>
                    <td>
                        <button
                            type="button"
                            class="btn btn-outline btn-sm"
                            onclick="openEditHolidayModal(${event.id})"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            onclick="openDeactivateEventModal(${event.id}, '${escapeHolidayHTML(event.event_name).replace(/'/g, "\\'")}')"
                        >
                            Deactivate
                        </button>
                    </td>
                </tr>
            `;

        }).join("");

    } catch (error) {
        console.error("Failed to load school schedule changes:", error);
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;">Failed to load schedule changes.</td></tr>`;
    }
}


/* =========================================================
   DEACTIVATE MODAL
   ========================================================= */

let eventIdToDeactivate = null;

function openDeactivateEventModal(id, name) {
    eventIdToDeactivate = id;
    document.getElementById("deactivateEventName").textContent = name;
    document.getElementById("deactivateEventModal").classList.add("active");
    lockBodyScroll();
}

function closeDeactivateEventModal() {
    eventIdToDeactivate = null;
    document.getElementById("deactivateEventModal").classList.remove("active");
    unlockBodyScroll();
}

async function confirmDeactivateSchoolEvent() {

    if (!eventIdToDeactivate) { return; }

    try {

        const formData = new FormData();
        formData.append("id", eventIdToDeactivate);

        const response = await fetch(appUrl("data/school-events/deactivate"), {
            method: "POST",
            body: formData
        });

        if (!response.ok) {
            throw new Error("HTTP error: " + response.status);
        }

        const result = await response.json();

        if (!result.success) {
            showToast("Failed to deactivate event: " + result.message, "error");
            closeDeactivateEventModal();
            return;
        }

        showToast("School schedule change moved to the Recycle Bin.", "success");
        closeDeactivateEventModal();
        loadSchoolHolidays();

    } catch (error) {
        console.error("Deactivate Event Error:", error);
        showToast("An error occurred while deactivating the event.", "error");
        closeDeactivateEventModal();
    }

}


/* =========================================================
   ESCAPE HTML
   ========================================================= */

function escapeHolidayHTML(value) {

    const div = document.createElement("div");
    div.textContent = value ?? "";

    return div.innerHTML;

}


/* =========================================================
   LOAD ON PAGE
   ========================================================= */

document.addEventListener("DOMContentLoaded", function () {
    loadSchoolHolidays();
});

/* =========================================================
   SAVE SCHOOL HOLIDAY
   ========================================================= */

async function saveSchoolHoliday() {

    const name = document.getElementById("holidayNameInput").value.trim();
    const type = document.getElementById("holidayTypeInput").value;
    const dateFrom = document.getElementById("holidayDateFromInput").value;
    const dateTo = document.getElementById("holidayDateToInput").value;
    const duration = document.getElementById("holidayDurationInput").value;
    const startTime = document.getElementById("holidayStartTimeInput").value;
    const endTime = document.getElementById("holidayEndTimeInput").value;
    const included = document.getElementById("holidayIncludedInput").value;
    const status = document.getElementById("holidayStatusInput").value;
    const editId = document.getElementById("holidayEditId").value;

    if (!name) {
        showErrorModal("Please enter the name.");
        return;
    }

    if (!dateFrom || !dateTo) {
        showErrorModal("Please select both start and end dates.");
        return;
    }

    if (dateFrom > dateTo) {
        showErrorModal("End date cannot be earlier than the start date.");
        return;
    }

    if (duration === "Half Day" && (!startTime || !endTime)) {
        showErrorModal("Please enter both start and end time for a Half Day schedule change.");
        return;
    }

    if (duration === "Half Day" && startTime && endTime && endTime <= startTime) {
        showErrorModal("End time must be later than start time.");
        return;
    }

    try {

        const formData = new FormData();
        formData.append("event_name", name);
        formData.append("event_type", type);
        formData.append("date_from", dateFrom);
        formData.append("date_to", dateTo);
        formData.append("duration", duration);
        formData.append("start_time", duration === "Half Day" ? startTime : "");
        formData.append("end_time", duration === "Half Day" ? endTime : "");
        formData.append("included_in_total_hours", included);
        // Remark/Description is not collected (per current UI) —
        // existing records that already have one keep it untouched.
        formData.append("remark", "");
        formData.append("status", status);
        holidayPersonnelSelected.forEach(id => formData.append("personnel_ids[]", id));

        if (editId) {
            formData.append("id", editId);
        }

        const response = await fetch(
            editId ? appUrl("data/school-events/update") : appUrl("data/school-events"),
            { method: "POST", body: formData }
        );
        const result = await response.json();

        if (!result.success) {
            showErrorModal("Failed to save: " + result.message);
            return;
        }

        showToast(
            editId
                ? "School schedule change updated successfully!"
                : "School schedule change added successfully!",
            "success"
        );
        closeHolidayModal();
        loadSchoolHolidays();

    } catch (error) {
        console.error("Save Schedule Change Error:", error);
        showErrorModal("An error occurred while saving.");
    }

}

/* =========================================================
   PERSONNEL SCHEDULED TO WORK (multi-select)
   Selected ids are sent as personnel_ids[]; there is no
   "All Personnel" flag — Select All simply selects every
   currently active personnel record.
   ========================================================= */

const holidayPersonnelAll = window.PAGE.schedulePicker;
let holidayPersonnelSelected = new Set();

function setHolidayPersonnel(ids) {
    holidayPersonnelSelected = new Set((ids || []).map(Number));
    const search = document.getElementById("holidayPersonnelSearch");
    if (search) search.value = "";
    renderHolidayPersonnelList();
}

function updateHolidayPersonnelCount() {
    const el = document.getElementById("holidayPersonnelCount");
    if (el) el.textContent = holidayPersonnelSelected.size + " selected";
}

function renderHolidayPersonnelList() {
    const list = document.getElementById("holidayPersonnelList");
    if (!list) return;

    const term = (document.getElementById("holidayPersonnelSearch").value || "").trim().toLowerCase();

    const visible = holidayPersonnelAll.filter(p =>
        !term ||
        p.fullname.toLowerCase().includes(term) ||
        p.id_number.toLowerCase().includes(term) ||
        p.department.toLowerCase().includes(term)
    );

    list.innerHTML = "";

    if (visible.length === 0) {
        const empty = document.createElement("div");
        empty.className = "personnel-picker-empty";
        empty.textContent = holidayPersonnelAll.length === 0 ? "No personnel available." : "No personnel match your search.";
        list.appendChild(empty);
    }

    visible.forEach(p => {
        const label = document.createElement("label");
        label.className = "personnel-option";

        const box = document.createElement("input");
        box.type = "checkbox";
        box.checked = holidayPersonnelSelected.has(p.id);
        box.addEventListener("change", () => {
            if (box.checked) holidayPersonnelSelected.add(p.id);
            else holidayPersonnelSelected.delete(p.id);
            updateHolidayPersonnelCount();
        });

        const text = document.createElement("span");
        text.textContent = p.fullname;
        const meta = document.createElement("small");
        meta.textContent = [p.id_number, p.department].filter(Boolean).join(" • ");
        text.appendChild(meta);

        label.appendChild(box);
        label.appendChild(text);
        list.appendChild(label);
    });

    updateHolidayPersonnelCount();
}

// Select All acts on the personnel currently shown, so a search narrows it.
function selectAllHolidayPersonnel() {
    const term = (document.getElementById("holidayPersonnelSearch").value || "").trim().toLowerCase();
    holidayPersonnelAll.forEach(p => {
        if (!term ||
            p.fullname.toLowerCase().includes(term) ||
            p.id_number.toLowerCase().includes(term) ||
            p.department.toLowerCase().includes(term)) {
            holidayPersonnelSelected.add(p.id);
        }
    });
    renderHolidayPersonnelList();
}

function clearHolidayPersonnel() {
    holidayPersonnelSelected = new Set();
    renderHolidayPersonnelList();
}

function toggleHolidayHalfDayFields() {
    const duration = document.getElementById("holidayDurationInput").value;
    const fields = document.getElementById("holidayHalfDayFields");
    fields.style.display = duration === "Half Day" ? "block" : "none";
}

function openHolidayModal() {
    const modal = document.getElementById("holidayModal");

    // Reset to defaults every time it's opened
    document.getElementById("holidayNameInput").value = "";
    document.getElementById("holidayTypeInput").value = "Holiday";
    document.getElementById("holidayDateFromInput").value = "";
    document.getElementById("holidayDateToInput").value = "";
    document.getElementById("holidayDurationInput").value = "Whole Day";
    document.getElementById("holidayStartTimeInput").value = "";
    document.getElementById("holidayEndTimeInput").value = "";
    document.getElementById("holidayIncludedInput").value = "0";
    document.getElementById("holidayStatusInput").value = "Active";
    document.getElementById("holidayEditId").value = "";
    document.getElementById("holidayModalTitle").textContent = "Add School Schedule Change";
    toggleHolidayHalfDayFields();
    setHolidayPersonnel([]);

    if (modal) { modal.classList.add("active"); lockBodyScroll(); }
}

function openEditHolidayModal(id) {
    const modal = document.getElementById("holidayModal");
    const event = schoolEventsById[id];

    if (!event) {
        showErrorModal("Unable to load this schedule change.");
        return;
    }

    document.getElementById("holidayEditId").value = event.id;
    document.getElementById("holidayModalTitle").textContent = "Edit School Schedule Change";
    document.getElementById("holidayNameInput").value = event.event_name || "";
    document.getElementById("holidayTypeInput").value = event.event_type || "Holiday";
    document.getElementById("holidayDateFromInput").value = event.date_from || "";
    document.getElementById("holidayDateToInput").value = event.date_to || "";
    document.getElementById("holidayDurationInput").value = event.duration || "Whole Day";
    document.getElementById("holidayStartTimeInput").value = event.start_time ? event.start_time.substring(0, 5) : "";
    document.getElementById("holidayEndTimeInput").value = event.end_time ? event.end_time.substring(0, 5) : "";
    document.getElementById("holidayIncludedInput").value = Number(event.included_in_total_hours) === 1 ? "1" : "0";
    document.getElementById("holidayStatusInput").value = event.status || "Active";
    toggleHolidayHalfDayFields();
    setHolidayPersonnel(event.worker_ids || []);

    if (modal) { modal.classList.add("active"); lockBodyScroll(); }
}

function closeHolidayModal() {
    const modal = document.getElementById("holidayModal");
    if (modal) { modal.classList.remove("active"); unlockBodyScroll(); }
}

