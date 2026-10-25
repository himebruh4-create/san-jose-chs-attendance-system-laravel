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
   - the camera is checked on every scan and every few minutes (blocked,
     too dark, disconnected, not allowed) and the kiosk checks in; the
     Super Admin and Admin are alerted. Scanning never waits on it.
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

   Camera health (settings in window.KIOSK_CAMERA, from
   App\Support\KioskHealth): the camera's own errors and a check of the
   picture (too dark, or one flat color = lens covered) on every scan and
   every few minutes are reported to the server, which alerts the Super
   Admin and Admin. That report is also the kiosk's check-in. A camera
   problem never stops a scan.
========================= */

const CAMERA_CONFIG = window.KIOSK_CAMERA;

const camera = {
    video: document.getElementById("cameraPreview"),
    status: document.getElementById("cameraStatus"),
    canvas: document.createElement("canvas"),
    sample: document.createElement("canvas"),
    stream: null,
    // Photos are only taken once the first frame has arrived and the
    // camera has had a moment to adjust its exposure.
    ready: false,
    // Why it is not ready: "starting", "disconnected" or "denied".
    problem: "starting",
    lastReported: null,
    retryTimer: null,
    recheckTimer: null,
    idleTimer: null
};

// Reported states that keep a camera alert open on the server.
const CAMERA_ALERT_STATES = ["blocked", "disconnected", "denied"];

const CAMERA_MESSAGES = {
    ok: ["Camera on — a photo is taken with each scan", true],
    blocked: ["Camera blocked or too dark — scans are still saved", false],
    disconnected: ["Camera not available — scans are saved without a photo", false],
    denied: ["Camera not allowed in this browser — scans are saved without a photo", false]
};

function setCameraStatus(text, ok) {
    camera.status.textContent = text;
    camera.status.className = "camera-status " + (ok ? "camera-ok" : "camera-off");
}

function showCameraState(state) {
    if (CAMERA_MESSAGES[state]) setCameraStatus(CAMERA_MESSAGES[state][0], CAMERA_MESSAGES[state][1]);
}

function startCamera() {
    clearTimeout(camera.retryTimer);

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        // Not a secure connection: retrying cannot help.
        cameraLost("disconnected", false);
        setCameraStatus("Camera unavailable on this connection — scans are saved without a photo", false);
        return;
    }

    navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 } }, audio: false })
        .then(function (stream) {
            camera.stream = stream;
            stream.getVideoTracks().forEach(function (track) {
                // Unplugged, or taken by the system.
                track.addEventListener("ended", function () { cameraLost("disconnected", true); });
            });
            camera.video.srcObject = stream;

            return camera.video.play().then(waitForFirstFrame);
        })
        .then(function () {
            camera.ready = true;
            camera.problem = null;
            checkCamera(true);
        })
        .catch(function (error) {
            const name = error && error.name;
            cameraLost(name === "NotAllowedError" || name === "SecurityError" ? "denied" : "disconnected", true);
        });
}

/* Resolves once a real frame has been shown, plus the warm-up time. */
function waitForFirstFrame() {
    return new Promise(function (resolve) {
        const afterWarmUp = function () { setTimeout(resolve, CAMERA_CONFIG.warmupMs); };

        if (camera.video.requestVideoFrameCallback) {
            camera.video.requestVideoFrameCallback(afterWarmUp);
        } else if (camera.video.readyState >= 2) {
            afterWarmUp();
        } else {
            camera.video.addEventListener("loadeddata", afterWarmUp, { once: true });
        }
    });
}

function cameraLost(problem, retry) {
    camera.ready = false;
    camera.problem = problem;

    if (camera.stream) {
        camera.stream.getTracks().forEach(function (track) { track.stop(); });
        camera.stream = null;
    }

    showCameraState(problem);
    if (camera.lastReported !== problem) reportCameraHealth(problem);

    clearTimeout(camera.retryTimer);
    if (retry) camera.retryTimer = setTimeout(startCamera, CAMERA_CONFIG.retryMs);
}

/* "ok" or "blocked" from the current picture, or why there is none. */
function cameraState() {
    if (!camera.ready || camera.video.readyState < 2 || !camera.video.videoWidth) {
        return camera.problem || "disconnected";
    }

    const width = CAMERA_CONFIG.sampleWidth;
    const height = CAMERA_CONFIG.sampleHeight;
    camera.sample.width = width;
    camera.sample.height = height;

    const context = camera.sample.getContext("2d", { willReadFrequently: true });
    context.drawImage(camera.video, 0, 0, width, height);
    const pixels = context.getImageData(0, 0, width, height).data;

    let sum = 0;
    let squares = 0;
    for (let i = 0; i < pixels.length; i += 4) {
        const luminance = 0.299 * pixels[i] + 0.587 * pixels[i + 1] + 0.114 * pixels[i + 2];
        sum += luminance;
        squares += luminance * luminance;
    }

    const count = width * height;
    const mean = sum / count;
    const stddev = Math.sqrt(Math.max(0, squares / count - mean * mean));

    return mean < CAMERA_CONFIG.darkMean || stddev < CAMERA_CONFIG.flatStddev ? "blocked" : "ok";
}

/* Checks the camera and reports it: always for the idle check-in
   (`always`), otherwise only when it changed. A newly blocked picture is
   checked again a little later (`confirming`) before it is reported, so
   someone leaning close to the camera does not raise an alert. */
function checkCamera(always, confirming) {
    let state;
    try {
        state = cameraState();
    } catch (error) {
        console.error("Camera check failed:", error);
        return "ok";
    }

    if (state === "starting") {
        if (always) reportCameraHealth("starting");
        return state;
    }

    showCameraState(state);

    if (state === "blocked" && camera.lastReported !== "blocked" && !confirming) {
        if (!camera.recheckTimer) {
            camera.recheckTimer = setTimeout(function () {
                camera.recheckTimer = null;
                checkCamera(true, true);
            }, CAMERA_CONFIG.recheckMs);
        }
    } else if (always || camera.lastReported !== state) {
        reportCameraHealth(state);
    }

    return state;
}

function reportCameraHealth(state) {
    const alertChanged = hasCameraAlert(camera.lastReported) !== hasCameraAlert(state);
    camera.lastReported = state;
    if (alertChanged) scheduleIdleCheck();

    fetch(CAMERA_CONFIG.healthUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ camera: state })
    }).catch(function () {});
}

function hasCameraAlert(state) {
    return CAMERA_ALERT_STATES.indexOf(state) !== -1;
}

/* The idle camera check, which is also the kiosk's check-in: every few
   minutes, or more often while a camera alert is open so it closes soon
   after the camera is fixed. */
function scheduleIdleCheck() {
    clearTimeout(camera.idleTimer);

    const delay = hasCameraAlert(camera.lastReported) ? CAMERA_CONFIG.alertCheckIntervalMs : CAMERA_CONFIG.checkIntervalMs;
    camera.idleTimer = setTimeout(function () {
        checkCamera(true);
        scheduleIdleCheck();
    }, delay);
}

/* =========================
   FACE DETECTION ("No face detected" flag)
   MediaPipe Face Detection, bundled with the app (works offline), run on
   the scan photo in this browser. Only the yes/no result is sent; it is a
   flag for the Super Admin, never a reason to refuse a scan.
========================= */

let faceDetector = null;

async function loadFaceDetector() {
    try {
        const vision = await import(CAMERA_CONFIG.faceDetector.module);
        const fileset = await vision.FilesetResolver.forVisionTasks(CAMERA_CONFIG.faceDetector.wasm);

        faceDetector = await vision.FaceDetector.createFromOptions(fileset, {
            baseOptions: { modelAssetPath: CAMERA_CONFIG.faceDetector.model, delegate: "CPU" },
            runningMode: "IMAGE",
            minDetectionConfidence: 0.5
        });
    } catch (error) {
        console.warn("Face detection is not available; scans are sent without it.", error);
    }
}

/* "1" a face, "0" none, "" not checked. */
function faceResult(image) {
    if (!faceDetector) return "";

    try {
        return faceDetector.detect(image).detections.length > 0 ? "1" : "0";
    } catch (error) {
        console.error("Face detection failed:", error);
        return "";
    }
}

function capturePhoto() {
    if (!camera.ready || camera.video.readyState < 2 || !camera.video.videoWidth) return "";

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
        <div class="scan-details">
            ${name}
            <b>Reason:</b> ${reason}
        </div>
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
    const cameraNow = checkCamera(false);
    const snapshot = capturePhoto();
    // A blocked picture has no face to find; the server flags it as blocked.
    const face = snapshot && cameraNow === "ok" ? faceResult(camera.canvas) : "";

    const body = new URLSearchParams();
    body.set("barcode", barcode);
    body.set("photo", snapshot);
    body.set("input_method", typedManually ? "typed" : "scanned");
    body.set("face_detected", face);

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
            <div class="scan-details">
                <b>Name:</b> ${escapeHtml(data.fullname)}<br>
                ${data.position ? `<b>Position:</b> ${escapeHtml(data.position)}<br>` : ""}
                <b>Scan:</b> ${escapeHtml(data.title)} &middot; ${escapeHtml(shortTime(now))}<br>
                ${data.status ? `<b>Status:</b> ${pillHTML(data.status)}<br>` : ""}
                <b>Message:</b> ${escapeHtml(data.message)}
                ${typedManually ? `<div class="scan-flag"><i class="fa-solid fa-keyboard"></i> Entered manually — flagged for verification.</div>` : ""}
            </div>
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
    loadFaceDetector();
    focusBarcode();

    scheduleIdleCheck();
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
