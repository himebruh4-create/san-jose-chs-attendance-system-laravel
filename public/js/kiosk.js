/* =========================================================
   ATTENDANCE KIOSK (was the inline script of teacher-dashboard.php)

   Changes from the native-PHP version:
   - the barcode box is cleared after EVERY scan (a failed scan used to
     leave its barcode in the box, so the next person's scan re-sent it)
   - the box keeps keyboard focus, so a USB scanner always types into it
   - a scan that cannot reach the server says so on screen
   - Late / On-Time comes from the response's `status` field
   - names and messages are escaped before being shown
   - a webcam photo is taken with each scan (when a camera is available)
     and shown in the result next to the person's ID photo
   ========================================================= */

let activeTeacherId = localStorage.getItem("activeTeacherId")
    ? Number(localStorage.getItem("activeTeacherId"))
    : null;

const modal = document.getElementById("modal");
const modalCountdownBar = document.getElementById("modalCountdown");
const barcodeInput = document.getElementById("barcodeInput");
let modalTimer = null;
let modalCountdown = null;

/* =========================
   MODAL
   Scan results close on their own: an accepted scan after 5 s; a rejected
   scan or a typed (flagged) entry after 7 s, so there is time to read why.
   Other messages keep 3 s; the weekly DTR stays until closed. A bar at the
   bottom shrinks over the countdown. A click/tap outside closes it at once,
   and so does the next barcode (see the input listener at the bottom).
========================= */

const MODAL_CLOSE_MS = {
    message: 3000,
    accepted: 5000,
    needsAttention: 7000
};

function isModalOpen() {
    return modal.style.display === "flex";
}

function stopModalCountdown() {
    clearTimeout(modalTimer);
    modalTimer = null;

    if (modalCountdown) {
        modalCountdown.cancel();
        modalCountdown = null;
    }
}

function hideModal(){
    stopModalCountdown();
    modal.style.display = "none";
    document.body.classList.remove('modal-open');
    focusBarcode();
}

modal.addEventListener("click", function(e){
    if (e.target === modal) {
        hideModal();
    }
});

/** closeAfterMs: milliseconds before it closes by itself, or false to stay open. */
function showModal(title, message, closeAfterMs = MODAL_CLOSE_MS.message){
    stopModalCountdown();

    document.getElementById("title").innerText = title;
    document.getElementById("message").innerHTML = message;
    modal.style.display = "flex";
    document.body.classList.add('modal-open');

    modalCountdownBar.hidden = !closeAfterMs;

    if (closeAfterMs) {
        modalTimer = setTimeout(hideModal, closeAfterMs);

        if (modalCountdownBar.animate) {
            modalCountdown = modalCountdownBar.animate(
                [{ transform: "scaleX(1)" }, { transform: "scaleX(0)" }],
                { duration: closeAfterMs, easing: "linear", fill: "forwards" }
            );
        }
    }

    // The barcode box keeps focus behind the modal, so the next scan is captured.
    focusBarcode();
}

/* =========================
   KEEP THE BARCODE BOX FOCUSED
   A USB scanner types like a keyboard: if the box loses focus, the
   next scan goes nowhere.
========================= */

function focusBarcode() {
    if (document.getElementById("sidebarDrawer").classList.contains("open")) return;
    barcodeInput.focus();
}

barcodeInput.addEventListener("blur", function () {
    setTimeout(focusBarcode, 150);
});

document.addEventListener("click", function (e) {
    if (!e.target.closest("a, button, .eye-icon")) focusBarcode();
});

/* =========================
   WEBCAM
   Browsers only allow the camera on http://localhost or https://.
========================= */

const camera = {
    video: document.getElementById("cameraPreview"),
    status: document.getElementById("cameraStatus"),
    canvas: document.createElement("canvas"),
    ready: false
};

function setCameraStatus(text, ok) {
    camera.status.textContent = text;
    camera.status.className = "camera-status " + (ok ? "camera-ok" : "camera-off");
}

function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setCameraStatus("Camera unavailable on this connection — scans are saved without a photo", false);
        return;
    }

    navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 } }, audio: false })
        .then(function (stream) {
            camera.video.srcObject = stream;
            camera.video.play();
            camera.ready = true;
            setCameraStatus("Camera on — a photo is taken with each scan", true);
        })
        .catch(function () {
            setCameraStatus("Camera not available — scans are saved without a photo", false);
        });
}

function capturePhoto() {
    if (!camera.ready || !camera.video.videoWidth) return "";

    const width = 480;
    const height = Math.round(width * camera.video.videoHeight / camera.video.videoWidth);
    camera.canvas.width = width;
    camera.canvas.height = height;
    camera.canvas.getContext("2d").drawImage(camera.video, 0, 0, width, height);

    return camera.canvas.toDataURL("image/jpeg", 0.7);
}

/* =========================
   BARCODE PROCESS
========================= */

let scanInProgress = false;

/* =========================
   MANUAL ENTRY DETECTION
   A USB scanner types the whole barcode in one burst, a few milliseconds
   per digit; a person typing is far slower, and a pasted barcode has no
   digit keystrokes at all. Sent with the scan as `input_method`; the
   server flags typed scans for the Super Admin.
========================= */

const SCANNER_MAX_KEY_GAP_MS = 50;
let digitKeyTimes = [];

function wasTypedManually(barcode) {
    const times = digitKeyTimes;
    digitKeyTimes = [];

    if (times.length !== barcode.length || times.length < 2) return true;

    const averageGap = (times[times.length - 1] - times[0]) / (times.length - 1);
    return averageGap > SCANNER_MAX_KEY_GAP_MS;
}

/* =========================
   SCAN RESULT PHOTOS
   Left: the ID photo on record (school logo when there is none).
   Right: the webcam snapshot sent with this scan.
========================= */

function photoPairHTML(recordPhoto, snapshot) {
    const logo = appUrl("img/logo.png");
    const recordSrc = recordPhoto ? appUrl("photos/" + encodeURIComponent(recordPhoto)) : logo;
    const snapshotHTML = snapshot
        ? `<img src="${snapshot}" alt="Photo taken at this scan">`
        : `<div class="photo-missing">No photo captured</div>`;

    return `
        <div class="photo-pair">
            <figure>
                <img src="${recordSrc}" alt="ID photo on record" onerror="this.onerror=null; this.src='${logo}';">
                <figcaption>On record</figcaption>
            </figure>
            <figure>
                ${snapshotHTML}
                <figcaption>Just now</figcaption>
            </figure>
        </div>`;
}

/** "07:28 AM" -> "7:28 AM" */
function shortTime(time) {
    return String(time || "").replace(/^0(?=\d:)/, "");
}

function rejectionHTML(data, snapshot, reason) {
    const name = data && data.fullname
        ? `<b>Name:</b> ${escapeHtml(data.fullname)}<br>`
        : "";

    return photoPairHTML(data && data.photo, snapshot) + `
        ${name}
        <b>Reason:</b> ${reason}
    `;
}

function pillHTML(status) {
    if (status === "On-Time") return `<span class="status-pill status-ontime"><i class="fa-solid fa-circle-check"></i> On-Time</span>`;
    if (status === "Late") return `<span class="status-pill status-late"><i class="fa-solid fa-triangle-exclamation"></i> Late</span>`;
    return `<span class="status-none">-</span>`;
}

function processBarcode() {

    if (scanInProgress) return;

    const barcode = barcodeInput.value.trim();
    if (!barcode) return;

    // The next person does not wait for the previous result to time out.
    if (isModalOpen()) hideModal();

    // Clear right away: whatever happens next, the box is ready for the
    // next person.
    barcodeInput.value = "";
    scanInProgress = true;

    const typedManually = wasTypedManually(barcode);
    const snapshot = capturePhoto();

    const body = new URLSearchParams();
    body.set("barcode", barcode);
    body.set("photo", snapshot);
    body.set("input_method", typedManually ? "typed" : "scanned");

    fetch(appUrl("kiosk/scan"), {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString()
    })
    .then(res => res.json())
    .then(data => {

        if (!data.success) {
            showModal(data.title || "Not Recorded", rejectionHTML(data, snapshot, escapeHtml(data.message || "Your scan was not recorded. Please try again.")), MODAL_CLOSE_MS.needsAttention);
            return;
        }

        const teacherId = Number(data.teacher_id);
        const now = data.time;

        /* -----------------------------
           TABLE UPDATE FIRST
           (row must exist before we touch its eye icon)
        ----------------------------- */

        const table = document.getElementById("attendanceTable");

        const emptyRow = table.querySelector(".empty-row");
        if (emptyRow) {
            emptyRow.closest("tr").remove();
        }

        let row = document.querySelector(`tr[data-teacher="${teacherId}"]`);

        if (!row) {

            row = document.createElement("tr");
            row.setAttribute("data-teacher", teacherId);

            row.innerHTML = `
                <td>${escapeHtml(data.fullname)}</td>
                <td>${escapeHtml(data.date)}</td>
                <td>-</td>
                <td>-</td>
                <td><span class="status-none">-</span></td>
                <td>-</td>
                <td>-</td>
                <td><span class="status-none">-</span></td>
                <td>
                    <i class="fa-solid fa-eye-slash eye-icon locked"
                    data-teacher="${teacherId}"
                    onclick="viewDTR(this)"></i>
                </td>
            `;

            table.insertBefore(row, table.children[1]);
        }

        if (data.title === "AM Arrival") {
            row.children[2].innerText = now;
            row.children[4].innerHTML = pillHTML(data.status);
        }

        if (data.title === "AM Departure") {
            row.children[3].innerText = now;
        }

        if (data.title === "PM Arrival") {
            row.children[5].innerText = now;
            row.children[7].innerHTML = pillHTML(data.status);
        }

        if (data.title === "PM Departure") {
            row.children[6].innerText = now;
        }

        // always move row to top
        table.insertBefore(row, table.children[1]);

        /* -----------------------------
           EYE ICON LOGIC
        ----------------------------- */

        const previousTeacher = activeTeacherId;

        activeTeacherId = teacherId;
        localStorage.setItem("activeTeacherId", activeTeacherId);

        if (previousTeacher && previousTeacher !== activeTeacherId) {
            updateEyeIcon(previousTeacher, false);
        }

        updateEyeIcon(activeTeacherId, true);

        /* -----------------------------
           MODAL
        ----------------------------- */

        const messageHTML = `
            <b>Name:</b> ${escapeHtml(data.fullname)}<br>
            ${data.position ? `<b>Position:</b> ${escapeHtml(data.position)}<br>` : ""}
            <b>Scan:</b> ${escapeHtml(data.title)} &middot; ${escapeHtml(shortTime(now))}<br>
            ${data.status ? `<b>Status:</b> ${pillHTML(data.status)}<br>` : ""}
            <b>Message:</b> ${escapeHtml(data.message)}
            ${typedManually ? `<div class="scan-flag"><i class="fa-solid fa-keyboard"></i> Entered manually — flagged for verification.</div>` : ""}
        `;

        showModal(data.title, photoPairHTML(data.photo, snapshot) + messageHTML,
            typedManually ? MODAL_CLOSE_MS.needsAttention : MODAL_CLOSE_MS.accepted);
    })
    .catch(err => {
        console.error("Scan request failed:", err);
        showModal("Not Recorded", rejectionHTML(null, snapshot, "The attendance server could not be reached, so this scan was <b>not saved</b>. Please scan again. If this keeps happening, tell the Super Admin."), MODAL_CLOSE_MS.needsAttention);
    })
    .finally(() => {
        scanInProgress = false;
        focusBarcode();
    });
}

/* =========================
   VIEW DTR — only the person who just scanned (checked by the server)
========================= */

function viewDTR(el){

    const id = parseInt(el.getAttribute("data-teacher"));

    if (id !== activeTeacherId) {
        showModal("Access Denied", "You can only view your own DTR after scanning.");
        return;
    }

    fetch(appUrl("kiosk/dtr") + "?teacher_id=" + encodeURIComponent(id))
    .then(res => {
        if (!res.ok) {
            throw new Error(`Server responded with status ${res.status}`);
        }
        return res.json();
    })
    .then(data => {

        if (data.error) {
            showModal("Error", escapeHtml(data.error));
            return;
        }

        let html = `
        <h2 style="margin-bottom:5px;">WEEKLY TIME RECORD</h2>
        <h3 style="margin-top:0; color:#8a7d5c;">${escapeHtml(data.fullname)}</h3>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tr style="background:#14390f;color:white;">
            <th>Date</th>
            <th>AM In</th>
            <th>AM Out</th>
            <th>PM In</th>
            <th>PM Out</th>
        </tr>`;

        data.records.forEach(r => {
            html += `
            <tr>
                <td>${escapeHtml(r.date)}</td>
                <td>${escapeHtml(r.am_arrival || '-')}</td>
                <td>${escapeHtml(r.am_departure || '-')}</td>
                <td>${escapeHtml(r.pm_arrival || '-')}</td>
                <td>${escapeHtml(r.pm_departure || '-')}</td>
            </tr>`;
        });

        html += `</table>`;
        html += `<p style="margin-top:15px;font-weight:bold;color:#14390f;">
        Week Covered: ${escapeHtml(data.week_start)} - ${escapeHtml(data.week_end)}</p>`;

        showModal("Weekly DTR", html, false);
    })
    .catch(error => {
        console.error("View DTR Error:", error);
        showModal("Error", "Unable to load the weekly DTR right now. Please try again.");
    });
}

function updateEyeIcon(teacherId, unlocked) {

    const icon = document.querySelector(`.eye-icon[data-teacher="${teacherId}"]`);

    if (!icon) {
        return;
    }

    if (unlocked) {
        icon.classList.remove("fa-eye-slash", "locked");
        icon.classList.add("fa-eye");
    } else {
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash", "locked");
    }
}

window.addEventListener("load", function () {
    if (activeTeacherId) {
        updateEyeIcon(activeTeacherId, true);
    }
    startCamera();
    focusBarcode();
});

/* =========================
   SIDEBAR TOGGLE (the kiosk's own drawer)
========================= */

function toggleSidebar() {
    document.getElementById("sidebarDrawer").classList.toggle("open");
    document.getElementById("sidebarOverlay").classList.toggle("active");
}

function closeSidebar() {
    document.getElementById("sidebarDrawer").classList.remove("open");
    document.getElementById("sidebarOverlay").classList.remove("active");
    focusBarcode();
}

/* =========================
   LIVE CLOCK
========================= */

function updateLiveClock() {

    const now = new Date();

    const time = now.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    const date = now.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    });

    document.getElementById("liveClockTime").innerText = time;
    document.getElementById("liveClockDate").innerText = date;
}

updateLiveClock();
setInterval(updateLiveClock, 1000);

/* =========================
   ENTER KEY SUPPORT
========================= */

barcodeInput.addEventListener("keydown", function(e){
    if (/^\d$/.test(e.key)) {
        digitKeyTimes.push(performance.now());
    }

    if (e.key === "Enter") {
        e.preventDefault();
        processBarcode();
    }
});

/* =========================
   BARCODE INPUT: NUMBERS ONLY, MAX 6 DIGITS
========================= */

barcodeInput.addEventListener("input", function () {
    this.value = this.value.replace(/\D/g, "").slice(0, 6);

    // A new barcode is coming in (scanned, typed or pasted): clear the
    // previous result off the screen straight away.
    if (this.value !== "" && isModalOpen()) {
        hideModal();
    }

    if (this.value === "") {
        digitKeyTimes = [];
    }
});
