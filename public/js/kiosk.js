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
     and shown in the result
   - no attendance records are shown: the day's attendance log and the
     weekly DTR viewer are gone (DTRs are on the staff pages)
   ========================================================= */

const modal = document.getElementById("modal");
const modalCountdownBar = document.getElementById("modalCountdown");
const barcodeInput = document.getElementById("barcodeInput");
let modalTimer = null;
let modalCountdown = null;

/* =========================
   MODAL
   Every scan result closes on its own after 7 s and the kiosk is back to
   the camera and the barcode box. A bar at the bottom shrinks over the
   countdown. A click/tap outside closes it at once, and so does the next
   barcode (see the input listener at the bottom).
========================= */

const SCAN_RESULT_CLOSE_MS = 7000;

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

function showModal(title, message){
    stopModalCountdown();

    document.getElementById("title").innerText = title;
    document.getElementById("message").innerHTML = message;
    modal.style.display = "flex";
    document.body.classList.add('modal-open');

    modalCountdownBar.hidden = false;
    modalTimer = setTimeout(hideModal, SCAN_RESULT_CLOSE_MS);

    if (modalCountdownBar.animate) {
        modalCountdown = modalCountdownBar.animate(
            [{ transform: "scaleX(1)" }, { transform: "scaleX(0)" }],
            { duration: SCAN_RESULT_CLOSE_MS, easing: "linear", fill: "forwards" }
        );
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
    if (!e.target.closest("a, button")) focusBarcode();
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
   SCAN RESULT PHOTO
   Only the webcam snapshot sent with this scan ("Just now").
========================= */

function scanPhotoHTML(snapshot) {
    const snapshotHTML = snapshot
        ? `<img src="${snapshot}" alt="Photo taken at this scan">`
        : `<div class="photo-missing">No photo captured</div>`;

    return `
        <div class="scan-photo">
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

    return scanPhotoHTML(snapshot) + `
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
            showModal(data.title || "Not Recorded", rejectionHTML(data, snapshot, escapeHtml(data.message || "Your scan was not recorded. Please try again.")));
            return;
        }

        const now = data.time;

        const messageHTML = `
            <b>Name:</b> ${escapeHtml(data.fullname)}<br>
            ${data.position ? `<b>Position:</b> ${escapeHtml(data.position)}<br>` : ""}
            <b>Scan:</b> ${escapeHtml(data.title)} &middot; ${escapeHtml(shortTime(now))}<br>
            ${data.status ? `<b>Status:</b> ${pillHTML(data.status)}<br>` : ""}
            <b>Message:</b> ${escapeHtml(data.message)}
            ${typedManually ? `<div class="scan-flag"><i class="fa-solid fa-keyboard"></i> Entered manually — flagged for verification.</div>` : ""}
        `;

        showModal(data.title, scanPhotoHTML(snapshot) + messageHTML);
    })
    .catch(err => {
        console.error("Scan request failed:", err);
        showModal("Not Recorded", rejectionHTML(null, snapshot, "The attendance server could not be reached, so this scan was <b>not saved</b>. Please scan again. If this keeps happening, tell the Super Admin."));
    })
    .finally(() => {
        scanInProgress = false;
        focusBarcode();
    });
}

window.addEventListener("load", function () {
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
