/* DTR VIEW — moved unchanged from the native-PHP dtr/dtr-view.php (a <script>
   included by both attendance report pages). Only the URLs are new.
   The rule functions in here are the JavaScript twin of
   app/Domain/Attendance/rules.php. */

// DTR.VIEW
/* =========================================================
   GLOBAL CURRENT DTR TEACHER 
   ========================================================= */

window.currentDTRTeacher = null;


/* =========================================================
   PRINT INDIVIDUAL REPORT
   ========================================================= */

async function printIndividualReport(teacher) {

    window.currentDTRTeacher = teacher;

    if (!teacher || !teacher.records) {
        showMessageModal("No attendance records were found for this person.", { title: "No Records Found" });
        return;
    }

    // =====================================================
    // GET SELECTED MONTH (moved up — adjustments need this)
    // =====================================================

    let selected = document.querySelector(".monthPicker")?.value;
    let now = new Date();
    let year = now.getFullYear();
    let month = now.getMonth();

    if (selected) {
        let parts = selected.split("-");
        year = parseInt(parts[0]);
        month = parseInt(parts[1]) - 1;
    }

    const monthStart = `${year}-${String(month + 1).padStart(2, "0")}-01`;
    const lastDay = new Date(year, month + 1, 0).getDate();
    const monthEnd = `${year}-${String(month + 1).padStart(2, "0")}-${String(lastDay).padStart(2, "0")}`;

    // =====================================================
    // GET TEACHER LEAVES FROM DATABASE
    // =====================================================

    let databaseLeaves = [];

    try {
        const response = await fetch(
            `${appUrl('data/teacher-leaves')}?teacher_id=${teacher.id}`
        );
        const result = await response.json();
        if (result.success) databaseLeaves = result.leaves || [];
    } catch (error) {
        console.error("Failed to load teacher leaves:", error);
        showMessageModal("Unable to load teacher leave records.", { type: "error" });
        return;
    }

    // =====================================================
    // GET ATTENDANCE ADJUSTMENTS FROM DATABASE
    // =====================================================

    let databaseAdjustments = [];

    try {
        const response = await fetch(
            `${appUrl('data/teacher-adjustments')}?teacher_id=${teacher.id}&start=${monthStart}&end=${monthEnd}`
        );
        const result = await response.json();
        if (result.success) databaseAdjustments = result.adjustments || [];
    } catch (error) {
        console.error("Failed to load attendance adjustments:", error);
        showMessageModal("Unable to load attendance adjustment records.", { type: "error" });
        return;
    }

    // =====================================================
    // GET SCHOOL HOLIDAYS FROM DATABASE
    // =====================================================

    let databaseHolidays = [];

    try {
        const response = await fetch(appUrl('data/school-events'));
        const result = await response.json();
        if (result.success) databaseHolidays = result.events || [];
    } catch (error) {
        console.error("Failed to load school events:", error);
        showMessageModal("Unable to load school event records.", { type: "error" });
        return;
    }

    // =====================================================
    // GET CONFIRMED ABSENCES FROM DATABASE
    // =====================================================

    let databaseConfirmedAbsences = [];

    try {
        const response = await fetch(
            `${appUrl('data/confirmed-absences')}?teacher_id=${teacher.id}&start=${monthStart}&end=${monthEnd}`
        );
        const result = await response.json();
        if (result.success) databaseConfirmedAbsences = result.absences || [];
    } catch (error) {
        console.error("Failed to load confirmed absences:", error);
        showMessageModal("Unable to load confirmed absence records.", { type: "error" });
        return;
    }

    // =====================================================
    // GET TEACHER SCHEDULE FROM DATABASE
    // =====================================================

    let teacherSchedule = {};

    try {
        const response = await fetch(
            `${appUrl('data/teacher-schedule')}?teacher_id=${teacher.id}`
        );
        const result = await response.json();
        if (result.success) teacherSchedule = result.schedule || {};
    } catch (error) {
        console.error("Failed to load teacher schedule:", error);
        showMessageModal("Unable to load teacher schedule.", { type: "error" });
        return;
    }

    // =====================================================
    // GET SHARED LUNCH BREAK SETTING
    // =====================================================

    let lunchSettings = { lunch_out: null, lunch_in: null };

    try {
        const response = await fetch(appUrl('data/recording-status'));
        const result = await response.json();
        if (result.success) {
            lunchSettings = {
                lunch_out: result.lunch_out || null,
                lunch_in: result.lunch_in || null,
                schedule_breaks: result.schedule_breaks || []
            };
        }
    } catch (error) {
        console.error("Failed to load lunch break settings:", error);
        // Not fatal — auto-fill for lunch fields simply won't apply.
    }

    // =====================================================
    // SHOW REMARK MODAL
    // =====================================================

    showDTRRemarkModal(
        teacher,
        year,
        month,
        monthStart,
        monthEnd,
        databaseLeaves,
        databaseAdjustments,
        databaseHolidays,
        databaseConfirmedAbsences,
        teacherSchedule,
        lunchSettings
    );
}



/* =========================================================
   HTML-ATTRIBUTE-SAFE JSON EMBEDDING
   showDTRRemarkModal() below builds the "Save & Print DTR" button's
   onclick as a single-quoted HTML attribute containing several
   JSON.stringify(...) blobs. Any apostrophe inside that JSON (e.g. a
   leave/adjustment reason such as "because he's late") would otherwise
   close the attribute early and corrupt the handler — same class of
   escaping already used server-side for data-teacher via
   htmlspecialchars(..., ENT_QUOTES, ...). This is the client-side
   equivalent, applied to each JSON blob before it is interpolated.
   The browser HTML-decodes the attribute before running it as JS, so
   the exact original JSON text (including the apostrophe) is restored
   before saveDTRRemarksAndPrint() ever sees it.
   ========================================================= */

function dtrEscapeHtmlAttr(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function dtrJsonForAttr(value) {
    return dtrEscapeHtmlAttr(JSON.stringify(value));
}



/* =========================================================
   DTR REMARK MODAL
   ========================================================= */

function showDTRRemarkModal(
    teacher,
    year,
    month,
    monthStart,
    monthEnd,
    databaseLeaves = [],
    databaseAdjustments = [],
    databaseHolidays = [],
    databaseConfirmedAbsences = [],
    teacherSchedule = {},
    lunchSettings = {}
) {

    // Remove existing modal
    const oldModal =
        document.getElementById("dtrRemarkModal");

    if (oldModal) {
        oldModal.remove();
    }


    // Remove old styles
    const oldStyle =
        document.getElementById("dtrRemarkStyles");

    if (oldStyle) {
        oldStyle.remove();
    }


    // =====================================================
    // CREATE MODAL
    // =====================================================

    const modal =
        document.createElement("div");

    modal.id = "dtrRemarkModal";


    modal.innerHTML = `

        <div class="dtr-remark-overlay">

            <div class="dtr-remark-box">


                <!-- HEADER -->

                <div class="dtr-remark-header">

                    <h2>DTR Date Remarks</h2>

                    <button
                        type="button"
                        class="dtr-close-btn"
                        onclick="closeDTRRemarkModal()"
                    >
                        ×
                    </button>

                </div>


                <!-- DESCRIPTION -->

                <p class="dtr-remark-description">

                    Add a special remark to a specific date for THIS teacher only.

                    Absent and other remarks are not counted as
                    regular working days in the DTR total. For Official
                    Business you choose the duration and whether it is
                    included in total hours.

                </p>


                <!-- REMARK ROWS -->

                <div id="dtrRemarkRows"></div>


                <!-- ADD REMARK -->

                <button
                    type="button"
                    class="add-dtr-remark-btn"
                    onclick="addDTRRemarkRow()"
                >
                    + Add Date Remark
                </button>


                <!-- ACTIONS -->

               <div class="dtr-remark-actions">

    <button
        type="button"
        class="dtr-cancel-btn"
        onclick="closeDTRRemarkModal()"
    >
        Cancel
    </button>

       <button
        type="button"
        class="dtr-print-btn"
        onclick='saveDTRRemarksAndPrint(
            ${teacher.id},
            "${monthStart}",
            "${monthEnd}",
            ${dtrJsonForAttr(databaseLeaves)},
            ${dtrJsonForAttr(databaseAdjustments)},
            ${dtrJsonForAttr(databaseHolidays)},
            ${dtrJsonForAttr(databaseConfirmedAbsences)},
            ${dtrJsonForAttr(teacherSchedule)},
            ${dtrJsonForAttr(lunchSettings)}
        )'
    >
        Save & Print DTR
    </button>

</div>


            </div>

        </div>

    `;


    document.body.appendChild(modal);



    /* =====================================================
       MODAL CSS
       ===================================================== */

    const style =
        document.createElement("style");

    style.id = "dtrRemarkStyles";


    style.innerHTML = `

        .dtr-remark-overlay {

            position: fixed;

            inset: 0;

            background: rgba(0,0,0,0.55);

            display: flex;

            justify-content: center;

            align-items: center;

            z-index: 99999;

            padding: 20px;

        }


        .dtr-remark-box {

            background: white;

            width: 600px;

            max-width: 100%;

            max-height: 90vh;

            overflow-y: auto;

            border-radius: 14px;

            padding: 25px;

            box-shadow:
                0 10px 35px
                rgba(0,0,0,0.3);

        }


        .dtr-remark-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 10px;

        }


        .dtr-remark-header h2 {

            margin: 0;

            color: #1b5e20;

        }


        .dtr-close-btn {

            border: none;

            background: transparent;

            font-size: 30px;

            cursor: pointer;

            color: #555;

        }


        .dtr-remark-description {

            font-size: 14px;

            color: #555;

            margin-bottom: 20px;

            line-height: 1.5;

        }


        /* =================================================
           REMARK ROW
           ================================================= */

        .dtr-remark-row {

            display: grid;

            grid-template-columns:
                150px
                1fr
                40px;

            gap: 10px;

            align-items: start;

            margin-bottom: 15px;

            padding: 12px;

            border: 1px solid #ddd;

            border-radius: 8px;

            background: #fafafa;

        }


        .dtr-remark-row label {

            display: block;

            font-size: 12px;

            font-weight: bold;

            margin-bottom: 5px;

            color: #444;

        }


        .dtr-remark-row input,

        .dtr-remark-row select {

            padding: 9px;

            border: 1px solid #ccc;

            border-radius: 7px;

            font-size: 14px;

            width: 100%;

            box-sizing: border-box;

        }


        /* =================================================
           OTHER INPUT
           ================================================= */

        .dtr-other-input {

            grid-column: 1 / 3;

        }


        /* =================================================
           REMOVE BUTTON
           ================================================= */

        .remove-dtr-remark {

            background: #dc3545;

            color: white;

            border: none;

            border-radius: 7px;

            padding: 9px;

            cursor: pointer;

            font-size: 16px;

        }


        .remove-dtr-remark:hover {

            background: #b02a37;

        }


        /* =================================================
           ADD BUTTON
           ================================================= */

        .add-dtr-remark-btn {

            width: 100%;

            padding: 11px;

            border: 2px dashed #1b5e20;

            background: #f1f8f1;

            color: #1b5e20;

            border-radius: 8px;

            cursor: pointer;

            font-size: 14px;

            font-weight: bold;

            margin-top: 5px;

        }


        .add-dtr-remark-btn:hover {

            background: #e8f5e9;

        }


        /* =================================================
           LEAVE FIELDS
           ================================================= */

        .leave-fields {

            grid-column: 1 / 3;

            display: grid;

            grid-template-columns:
                1fr
                1fr;

            gap: 10px;

            padding: 12px;

            background: #f5f5f5;

            border-radius: 8px;

            margin-top: 5px;

        }


        .leave-fields small {

            display: block;

            color: #777;

            margin-top: 3px;

        }


        /* =================================================
           OFFICIAL BUSINESS FIELDS (Duration / Session / Included)
           ================================================= */

        .ob-fields {

            grid-column: 1 / 3;

            display: grid;

            grid-template-columns:
                1fr
                1fr
                1fr;

            gap: 10px;

            padding: 12px;

            background: #f5f5f5;

            border-radius: 8px;

            margin-top: 5px;

        }


        /* =================================================
           ACTIONS
           ================================================= */

        .dtr-remark-actions {

            display: flex;

            justify-content: flex-end;

            gap: 10px;

            margin-top: 25px;

        }


        .dtr-cancel-btn,

        .dtr-print-btn {

            border: none;

            padding: 11px 18px;

            border-radius: 8px;

            cursor: pointer;

            font-size: 14px;

        }


        .dtr-cancel-btn {

            background: #ccc;

            color: #333;

        }


        .dtr-cancel-btn:hover {

            background: #bbb;

        }


        .dtr-print-btn {

            background:
                linear-gradient(
                    135deg,
                    #1b5e20,
                    #66bb6a
                );

            color: white;

            font-weight: bold;

        }


        .dtr-print-btn:hover {

            opacity: 0.9;

        }


        /* =================================================
           MOBILE
           ================================================= */

        @media (max-width: 600px) {

            .dtr-remark-row {

                grid-template-columns:
                    1fr
                    40px;

            }


            .remark-normal-date {

                grid-column: 1 / 2;

            }


            .dtr-remark-row > div:nth-child(2) {

                grid-column: 1 / 2;

            }


            .leave-fields,
            .ob-fields {

                grid-column: 1 / 2;

                grid-template-columns: 1fr;

            }


            .dtr-other-input {

                grid-column: 1 / 2;

            }

        }

    `;


    document.head.appendChild(style);


    // =====================================================
    // PRELOAD THIS TEACHER'S SAVED REMARKS FOR THE MONTH
    // (saving replaces the month's remarks, so the ones already
    // saved must be in the modal or they would be lost)
    // =====================================================

    loadSavedDTRRemarks(teacher.id, monthStart, monthEnd);
}


/* =========================================================
   LOAD SAVED REMARKS INTO THE MODAL
   ========================================================= */

async function loadSavedDTRRemarks(teacherId, monthStart, monthEnd) {

    let saved = [];

    try {

        const response = await fetch(
            `${appUrl('data/dtr-remarks')}?teacher_id=${teacherId}&start=${monthStart}&end=${monthEnd}`
        );

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || "Unable to load saved remarks.");
        }

        saved = result.remarks || [];

    } catch (error) {

        console.error("Failed to load saved DTR remarks:", error);

        // Without the saved remarks in the modal, saving would replace
        // them — so stop here instead of offering a misleading blank form.
        closeDTRRemarkModal();
        dtrShowError("Unable to load this teacher's saved DTR remarks. Please try again.");
        return;
    }

    if (saved.length === 0) {
        addDTRRemarkRow();
        return;
    }

    saved.forEach(remark => addDTRRemarkRow(remark));
}


/* =========================================================
   SHARED MESSAGES FOR THE DTR REMARK FLOW
   Self-contained (the Super Admin and Admin pages that include
   this file don't share one modal/toast implementation) and
   styled like the DTR Date Remarks modal. No browser alert().
   ========================================================= */

function dtrShowError(message) {

    const old = document.getElementById("dtrErrorModal");
    if (old) old.remove();

    const wrap = document.createElement("div");
    wrap.id = "dtrErrorModal";
    wrap.style.cssText =
        "position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; " +
        "justify-content:center; align-items:center; z-index:100000; padding:20px;";

    const box = document.createElement("div");
    box.style.cssText =
        "background:#fff; width:420px; max-width:100%; border-radius:14px; " +
        "padding:24px; box-shadow:0 10px 35px rgba(0,0,0,0.3); font-family:Arial, sans-serif;";

    const title = document.createElement("h3");
    title.textContent = "Something Went Wrong";
    title.style.cssText = "margin:0 0 10px; color:#1b5e20;";

    const text = document.createElement("p");
    text.textContent = message || "An error occurred.";
    text.style.cssText = "margin:0 0 18px; color:#555; font-size:14px; line-height:1.5;";

    const button = document.createElement("button");
    button.type = "button";
    button.textContent = "OK";
    button.style.cssText =
        "float:right; border:none; padding:10px 22px; border-radius:8px; cursor:pointer; " +
        "font-size:14px; font-weight:bold; color:#fff; background:linear-gradient(135deg,#1b5e20,#66bb6a);";
    button.onclick = () => wrap.remove();

    box.appendChild(title);
    box.appendChild(text);
    box.appendChild(button);
    box.appendChild(Object.assign(document.createElement("div"), { style: "clear:both;" }));
    wrap.appendChild(box);
    document.body.appendChild(wrap);
}

function dtrShowToast(message) {

    const old = document.getElementById("dtrToast");
    if (old) old.remove();

    const toast = document.createElement("div");
    toast.id = "dtrToast";
    toast.textContent = message;
    toast.style.cssText =
        "position:fixed; bottom:24px; right:24px; background:#1b5e20; color:#fff; " +
        "padding:12px 20px; border-radius:8px; font-size:13.5px; font-family:Arial, sans-serif; " +
        "box-shadow:0 8px 24px rgba(0,0,0,0.25); z-index:100000;";

    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}



/* =========================================================
   ADD DATE REMARK ROW
   ========================================================= */

function addDTRRemarkRow(prefill = null) {

    const container =
        document.getElementById("dtrRemarkRows");

    if (!container) {
        return;
    }


    const row =
        document.createElement("div");

    row.className =
        "dtr-remark-row";


    row.innerHTML = `


        <!-- =================================================
             NORMAL DATE
             ================================================= -->

        <div class="remark-normal-date">

            <label>Date</label>

            <input
                type="date"
                class="dtr-remark-date"
            >

        </div>


        <!-- =================================================
             REMARK TYPE
             ================================================= -->

        <div>

            <label>Remark</label>

           <select
    class="dtr-remark-type"
    onchange="handleRemarkType(this)">

    <option value="">Select Remark</option>
    <option value="Absent">Absent</option>
    <option value="Official Business">Official Business</option>
    <option value="Other">Other</option>
</select>


        </div>


        <!-- =================================================
             REMOVE
             ================================================= -->

        <button
            type="button"
            class="remove-dtr-remark"
            onclick="this.parentElement.remove()"
        >
            ×
        </button>


        <!-- =================================================
             ON LEAVE FIELDS
             ================================================= -->

        <div
            class="leave-fields"
            style="display:none;"
        >

            <div>

                <label>
                    Leave From
                </label>

                <input
                    type="date"
                    class="leave-date-from"
                >

            </div>


            <div>

                <label>
                    Leave Until
                </label>

                <input
                    type="date"
                    class="leave-date-to"
                >

            </div>


            <div>

                <label>
                    Actual Return Date
                </label>

                <input
                    type="date"
                    class="actual-return-date"
                >

                <small>
                    Optional
                </small>

            </div>


            <div>

                <label>
                    Status
                </label>

                <select
                    class="leave-status"
                >

                    <option value="Active">
                        Active
                    </option>

                    <option value="Completed">
                        Completed
                    </option>

                    <option value="Cancelled">
                        Cancelled
                    </option>

                </select>

            </div>

        </div>


        <!-- =================================================
             OFFICIAL BUSINESS FIELDS
             ================================================= -->

        <div
            class="ob-fields"
            style="display:none;"
        >

            <div>

                <label>Duration</label>

                <select
                    class="ob-duration"
                    onchange="handleOBDuration(this)"
                >
                    <option value="Whole Day">Whole Day</option>
                    <option value="Half Day">Half Day</option>
                </select>

            </div>

            <div class="ob-session-wrap" style="display:none;">

                <label>Session</label>

                <select class="ob-session">
                    <option value="">Select session</option>
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                </select>

            </div>

            <div>

                <label>Included in Total Hours</label>

                <select class="ob-included">
                    <option value="1">Yes</option>
                    <option value="0" selected>No</option>
                </select>

            </div>

        </div>


        <!-- =================================================
             OTHER REMARK
             ================================================= -->

        <input
            type="text"
            class="dtr-other-input"
            placeholder="Enter custom remark..."
            style="display:none;"
        >

    `;


    container.appendChild(row);

    if (prefill) {
        fillDTRRemarkRow(row, prefill);
    }
}


/* =========================================================
   FILL A ROW FROM A SAVED REMARK
   ========================================================= */

function fillDTRRemarkRow(row, remark) {

    const typeSelect = row.querySelector(".dtr-remark-type");

    // A saved type that isn't one of the current choices (e.g. a legacy
    // "On Leave" row) is kept as an option so re-saving doesn't drop it.
    if (![...typeSelect.options].some(o => o.value === remark.remark_type)) {
        const legacy = document.createElement("option");
        legacy.value = remark.remark_type;
        legacy.textContent = remark.remark_type;
        typeSelect.appendChild(legacy);
    }

    typeSelect.value = remark.remark_type;

    row.querySelector(".dtr-remark-date").value = remark.date || "";

    if (remark.remark_type === "Other") {
        row.querySelector(".dtr-other-input").value = remark.remark_text || "";
    }

    if (remark.remark_type === "On Leave") {
        row.querySelector(".leave-date-from").value = remark.date_from || "";
        row.querySelector(".leave-date-to").value = remark.date_to || "";
        row.querySelector(".actual-return-date").value = remark.actual_return_date || "";
        row.querySelector(".leave-status").value = remark.status || "Active";
    }

    if (remark.remark_type === "Official Business") {
        row.querySelector(".ob-duration").value = remark.duration === "Half Day" ? "Half Day" : "Whole Day";
        row.querySelector(".ob-session").value = remark.half_day_session || "";
        row.querySelector(".ob-included").value = Number(remark.included_in_total_hours) === 1 ? "1" : "0";
    }

    handleRemarkType(typeSelect);

    if (remark.remark_type === "Official Business") {
        handleOBDuration(row.querySelector(".ob-duration"));
    }
}



/* =========================================================
   HANDLE REMARK TYPE
   ========================================================= */

function handleRemarkType(select) {

    const row =
        select.closest(".dtr-remark-row");

    if (!row) {
        return;
    }


    const normalDate =
        row.querySelector(".remark-normal-date");

    const leaveFields =
        row.querySelector(".leave-fields");

    const otherInput =
        row.querySelector(".dtr-other-input");

    // Duration / Session / Included only apply to Official Business
    const obFields =
        row.querySelector(".ob-fields");

    if (obFields) {
        obFields.style.display =
            select.value === "Official Business" ? "grid" : "none";
    }


    // =====================================================
    // ON LEAVE
    // =====================================================

    if (select.value === "On Leave") {

        normalDate.style.display =
            "none";

        leaveFields.style.display =
            "grid";

        otherInput.style.display =
            "none";

        otherInput.value =
            "";

        return;
    }


    // =====================================================
    // OTHER
    // =====================================================

    if (select.value === "Other") {

        normalDate.style.display =
            "block";

        leaveFields.style.display =
            "none";

        otherInput.style.display =
            "block";

        return;
    }


    // =====================================================
    // NORMAL REMARK
    // =====================================================

    normalDate.style.display =
        "block";

    leaveFields.style.display =
        "none";

    otherInput.style.display =
        "none";

    otherInput.value =
        "";
}



/* =========================================================
   OFFICIAL BUSINESS: DURATION CHANGED
   The AM/PM session is only asked for on a Half Day.
   ========================================================= */

function handleOBDuration(select) {

    const row = select.closest(".dtr-remark-row");

    if (!row) {
        return;
    }

    const sessionWrap = row.querySelector(".ob-session-wrap");

    if (sessionWrap) {
        sessionWrap.style.display =
            select.value === "Half Day" ? "block" : "none";
    }

    if (select.value !== "Half Day") {
        const session = row.querySelector(".ob-session");
        if (session) session.value = "";
    }
}



/* =========================================================
   CLOSE REMARK MODAL
   ========================================================= */

function closeDTRRemarkModal() {

    const modal =
        document.getElementById(
            "dtrRemarkModal"
        );

    if (modal) {

        modal.remove();

    }
}



/* =========================================================
   SAVE REMARKS THEN PRINT
   ========================================================= */

async function saveDTRRemarksAndPrint(
    teacherId,
    monthStart,
    monthEnd,
    databaseLeaves = [],
    databaseAdjustments = [],
    databaseHolidays = [],
    databaseConfirmedAbsences = [],
    teacherSchedule = {},
    lunchSettings = {}
) {


    const rows =
        document.querySelectorAll(
            ".dtr-remark-row"
        );


    const remarks = [];


    let invalid = false;

    // First specific validation message wins (the collection loop
    // below can't stop early from inside forEach).
    let errorMessage = null;



    /* =====================================================
       COLLECT REMARKS
       ===================================================== */

    rows.forEach(row => {

        const type =
            row.querySelector(
                ".dtr-remark-type"
            ).value;


        // Ignore completely empty row
        if (!type) {
            return;
        }



        /* =================================================
           ON LEAVE
           ================================================= */

        if (type === "On Leave") {

            const dateFrom =
                row.querySelector(
                    ".leave-date-from"
                ).value;


            const dateTo =
                row.querySelector(
                    ".leave-date-to"
                ).value;


            const actualReturnDate =
                row.querySelector(
                    ".actual-return-date"
                ).value;


            const status =
                row.querySelector(
                    ".leave-status"
                ).value;



            // Required dates
            if (!dateFrom || !dateTo) {

                invalid = true;

                return;
            }



            // Start cannot be after end
            if (dateFrom > dateTo) {

                errorMessage = errorMessage ||
                    "The leave start date cannot be later than the leave end date.";

                invalid = true;

                return;
            }

            // Actual return date validation
            if (
                actualReturnDate &&
                actualReturnDate < dateFrom
            ) {

                errorMessage = errorMessage ||
                    "The actual return date cannot be earlier than the leave start date.";

                invalid = true;

                return;
            }



            // Actual return date should not be after
            // the leave period without reason
            if (
                actualReturnDate &&
                actualReturnDate > monthEnd
            ) {

                errorMessage = errorMessage ||
                    "The actual return date must be within the selected month.";

                invalid = true;

                return;
            }



            remarks.push({

                date: null,

                date_from:
                    dateFrom,

                date_to:
                    dateTo,

                actual_return_date:
                    actualReturnDate || null,

                status:
                    status,

                remark_type:
                    "On Leave",

                remark_text:
                    ""

            });


            return;
        }



        /* =================================================
           NORMAL REMARK
           ================================================= */

        const date =
            row.querySelector(
                ".dtr-remark-date"
            ).value;


        const other =
            row.querySelector(
                ".dtr-other-input"
            ).value.trim();



        // Date required
        if (!date) {

            invalid = true;

            return;
        }



        // Date must be inside selected month
        if (
            date < monthStart ||
            date > monthEnd
        ) {

            errorMessage = errorMessage ||
                "The remark date must be within the selected month.";

            invalid = true;

            return;
        }



        let remarkText = "";



        // OTHER
        if (type === "Other") {

            if (!other) {

                invalid = true;

                return;
            }


            remarkText =
                other;
        }


        // OFFICIAL BUSINESS — Duration / Session / Included in Total Hours.
        // Every other remark type is always Whole Day / not included, so
        // Absent and Other keep behaving exactly as they always have.
        let duration = "Whole Day";
        let halfDaySession = null;
        let includedInTotalHours = 0;

        if (type === "Official Business") {

            duration =
                row.querySelector(".ob-duration").value;

            includedInTotalHours =
                row.querySelector(".ob-included").value === "1" ? 1 : 0;

            if (duration === "Half Day") {

                halfDaySession =
                    row.querySelector(".ob-session").value;

                if (halfDaySession !== "AM" && halfDaySession !== "PM") {

                    errorMessage = errorMessage ||
                        "Please choose the AM or PM session for the Half Day Official Business.";

                    invalid = true;

                    return;
                }
            }
        }



        remarks.push({

            date:
                date,

            date_from:
                date,

            date_to:
                date,

            actual_return_date:
                null,

            status:
                "Active",

            remark_type:
                type,

            remark_text:
                remarkText,

            duration:
                duration,

            half_day_session:
                halfDaySession,

            included_in_total_hours:
                includedInTotalHours

        });

    });



    /* =====================================================
       INVALID DATA
       ===================================================== */

    if (invalid) {

        dtrShowError(
            errorMessage ||
            "Please complete all required remark information."
        );

        return;
    }



    /* =====================================================
       CHECK DUPLICATE / OVERLAPPING DATES
       ===================================================== */

    for (
        let i = 0;
        i < remarks.length;
        i++
    ) {

        for (
            let j = i + 1;
            j < remarks.length;
            j++
        ) {

            const a =
                remarks[i];

            const b =
                remarks[j];


            const aStart =
                a.date_from;

            const aEnd =
                a.date_to;


            const bStart =
                b.date_from;

            const bEnd =
                b.date_to;



            // Date ranges overlap
            const overlap =
                aStart <= bEnd &&
                bStart <= aEnd;



            if (overlap) {

                dtrShowError(
                    "You cannot add more than one remark covering the same date."
                );

                return;
            }

        }

    }



    /* =====================================================
       SAVE TO DATABASE
       ===================================================== */

    try {

        const formData =
            new FormData();


        formData.append(
            "teacher_id",
            teacherId
        );


        formData.append(
            "month_start",
            monthStart
        );


        formData.append(
            "month_end",
            monthEnd
        );


        formData.append(
            "remarks",
            JSON.stringify(remarks)
        );



        /* =================================================
           SEND TO PHP
           ================================================= */

        const response =
            await fetch(
                appUrl('data/dtr-remarks'),
                {
                    method: "POST",
                    body: formData
                }
            );



        const result =
            await response.json();



        /* =================================================
           CHECK SERVER RESULT
           ================================================= */

        if (!result.success) {

            dtrShowError(
                "Failed to save remarks: " +
                result.message
            );

            return;
        }

        dtrShowToast("DTR remarks saved successfully.");



        /* =================================================
           CLOSE MODAL
           ================================================= */

        closeDTRRemarkModal();



        /* =================================================
           PRINT DTR
           ================================================= */

               printDTRWithRemarks(
            teacherId,
            remarks,
            databaseLeaves,
            databaseAdjustments,
            databaseHolidays,
            databaseConfirmedAbsences,
            teacherSchedule,
            lunchSettings
        );



    } catch (error) {

        console.error(
            "DTR Save Error:",
            error
        );


        dtrShowError(
            "An error occurred while saving the DTR remarks."
        );

    }

}



/* =========================================================
   SHARED DTR PRINT STYLE
   Extracted verbatim from the Individual DTR print template so
   Generate All DTR can reuse the exact same look — one source
   of truth for both, per teacher.
   ========================================================= */

const DTR_STYLE_BLOCK = `
    @page { size: A4 portrait; margin: 10mm; }
    body { font-family: Arial, sans-serif; padding: 8px; }
    .container { width: 900px; max-width: 100%; margin: auto; border: 2px solid black; padding: 6px; box-sizing: border-box; }
    .print-logo-block { text-align: center; margin-bottom: 3px; }
    .print-logo-img { width: 34px; height: 34px; object-fit: contain; display: block; margin: 0 auto 2px; }
    .print-school-name { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
    .header { display: flex; justify-content: space-between; font-size: 11px; }
    .title { text-align: center; font-weight: bold; font-size: 15px; margin: 2px 0; }
    .subtitle { text-align: center; font-size: 10px; margin-bottom: 3px; }
    .name-line { text-align: center; border-bottom: 1px solid black; margin: 2px 0; padding-bottom: 2px; font-weight: bold; font-size: 13px; }
    .details { display: flex; justify-content: space-between; font-size: 10px; margin-bottom: 2px; gap: 20px; }
    .details b { border-bottom: 1px solid black; padding: 0 5px; }
    table { width: 100%; border-collapse: collapse; font-size: 9px; }
    th { font-weight: normal; }
    th, td { border: 1px solid black; padding: 1px; text-align: center; height: 19px; }
    td { height: 19px; vertical-align: middle; line-height: 1.1; }
    .special-day-row td { font-weight: normal; text-align: center; height: 19px; }
    .footer { font-size: 9px; margin-top: 4px; }
    .footer p { margin: 2px 0; }
    .right { text-align: right; }
    .legend { font-size: 8px; margin-top: 3px; color: #333; }
    .signature-row { display: flex; justify-content: space-between; margin-top: 16px; }
    .signature-block { width: 45%; text-align: center; }
    .signature-line { border-top: 1px solid black; margin-bottom: 3px; }
    .signature-name { font-weight: bold; font-size: 9px; }
    .signature-title { font-size: 8px; color: #333; margin-top: 1px; }
    .dtr-page-break { page-break-after: always; }
    @media print {
        body { padding: 0; }
        .container { width: 100%; page-break-inside: avoid; }
    }
`;

/* =========================================================
   PRINT DTR WITH REMARKS
   `overrideTeacher`/`overrideYear`/`overrideMonth` let Generate
   All DTR (see printAllDTR) reuse this exact function per teacher
   instead of the current single-teacher/current-month context.
   `returnContainerOnly` makes it return just the `.container` DIV
   (for bulk concatenation) instead of printing immediately.
   ========================================================= */

function printDTRWithRemarks(
    teacherId,
    remarks,
    databaseLeaves = [],
    databaseAdjustments = [],
    databaseHolidays = [],
    databaseConfirmedAbsences = [],
    teacherSchedule = {},
    lunchSettings = {},
    overrideTeacher = null,
    overrideYear = null,
    overrideMonth = null,
    returnContainerOnly = false
) {

    /* =====================================================
       GET TEACHER
       ===================================================== */

    const teacher = overrideTeacher || window.currentDTRTeacher;

    if (!teacher) {
        showMessageModal("Personnel information was not found.", { type: "error" });
        return;
    }

    /* =====================================================
       GET SELECTED MONTH
       ===================================================== */

    let now = new Date();
    let year = overrideYear !== null ? overrideYear : now.getFullYear();
    let month = overrideMonth !== null ? overrideMonth : now.getMonth();

    if (overrideYear === null) {
        let selected = document.querySelector(".monthPicker")?.value;
        if (selected) {
            const parts = selected.split("-");
            year = parseInt(parts[0]);
            month = parseInt(parts[1]) - 1;
        }
    }

    /* =====================================================
       TODAY (for Pending Review comparison)
       ===================================================== */

    const todayString = new Date().toISOString().split("T")[0];

    /* =====================================================
       MONTH NAME
       ===================================================== */

    const monthYear = new Date(year, month).toLocaleString(
        "default",
        { month: "long", year: "numeric" }
    );

    /* =====================================================
       FORMAT TIME
       ===================================================== */

    function format(time) {
        if (!time) return "";
        return new Date("1970-01-01T" + time).toLocaleTimeString(
            [],
            { hour: "2-digit", minute: "2-digit" }
        );
    }

    // Renders a cell's time, with a small "(Auto)" tag underneath
    // when that specific value was auto-filled rather than scanned.
    function cellHTML(time, isAuto) {
        if (!time) return "&nbsp;";
        const formatted = format(time);
        if (isAuto) {
            return `${formatted}<br><small style="color:#1565c0; font-weight:normal; font-size:6px; line-height:1;">(Auto)</small>`;
        }
        return formatted;
    }

    // Builds a readable summary of the teacher's actual weekly schedule,
    // grouping consecutive days that share the same time-in/time-out.
    // e.g. "Mon - Fri: 7:00 AM - 4:00 PM" or multiple groups if they differ.
    function buildScheduleSummary(schedule) {

        const order = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"];
        const labels = {
            monday: "Mon", tuesday: "Tue", wednesday: "Wed",
            thursday: "Thu", friday: "Fri", saturday: "Sat", sunday: "Sun"
        };

        const groups = [];
        let current = null;

        order.forEach(day => {

            const entry = schedule[day];

            if (!entry) {
                current = null;
                return;
            }

            const key = entry.time_in + "-" + entry.time_out;

            if (current && current.key === key) {
                current.days.push(day);
            } else {
                current = { key, days: [day], time_in: entry.time_in, time_out: entry.time_out };
                groups.push(current);
            }
        });

        if (groups.length === 0) {
            return "No schedule on file";
        }

        return groups.map(g => {
            const dayLabel = g.days.length > 1
                ? `${labels[g.days[0]]} - ${labels[g.days[g.days.length - 1]]}`
                : labels[g.days[0]];
            return `${dayLabel}: ${format(g.time_in)} - ${format(g.time_out)}`;
        }).join(" | ");
    }

    const scheduleSummary = buildScheduleSummary(teacherSchedule);

    /* =====================================================
       BUILD LOOKUP MAPS
       ===================================================== */

    // Manual per-teacher remarks (Absent / Other) keyed by date
    const remarkMap = {};

    remarks.forEach(r => {
        if (r.remark_type !== "On Leave") {
            remarkMap[r.date] = r;
        }
    });

    // Teacher leave ranges
    const leaveRemarks = [];

    databaseLeaves.forEach(leave => {
        if (leave.status === "Cancelled") return;

        leaveRemarks.push({
            date_from: leave.leave_from,
            date_to: leave.leave_until,
            leave_type: leave.leave_type,
            reason: leave.reason,
            status: leave.status,
            actual_return_date: leave.actual_return_date || null
        });
    });

    // Attendance adjustments keyed by date
    const adjustmentMap = {};

    databaseAdjustments.forEach(adj => {
        adjustmentMap[adj.adjustment_date] = adj;
    });

     // Active school events (holidays, asynchronous days, etc.)
    const holidayRanges = [];

    databaseHolidays.forEach(event => {
        if (event.status !== "Active") return;

        holidayRanges.push({
            id: event.id,
            worker_ids: (event.worker_ids || []).map(Number),
            date_from: event.date_from,
            date_to: event.date_to,
            event_name: event.event_name,
            event_type: event.event_type,
            duration: event.duration,
            included_in_total_hours: event.included_in_total_hours,
            start_time: event.start_time,
            end_time: event.end_time,
            remark: event.remark
        });
    });

    /* -----------------------------------------------------
       SCHEDULE-AWARE HELPERS — JavaScript twin of the PHP ones in
       dtr/dtr-core.php (dtrScheduleShape, dtrShiftHours, ...). A
       person's OWN schedule row is the source of truth: ordinary day
       schedules keep the AM / lunch / PM model; afternoon (e.g.
       14:00-22:00) and overnight (e.g. 22:00-06:00) schedules are
       single continuous shifts. Keep both versions in step.
       ----------------------------------------------------- */

    function scheduleShape(sch) {
        if (!sch || !sch.time_in || !sch.time_out) return "day";
        const tin = String(sch.time_in).slice(0, 8);
        const tout = String(sch.time_out).slice(0, 8);
        if (tout < tin) return "overnight";
        return tin >= "12:00:00" ? "afternoon" : "day";
    }

    function isShiftSchedule(sch) {
        return scheduleShape(sch) !== "day";
    }

    function isClockTime(v) {
        return typeof v === "string" && /^\d{2}:\d{2}(:\d{2})?$/.test(v);
    }

    function shiftArrival(rec) {
        const found = [rec.am_arrival, rec.pm_arrival].filter(v => v && isClockTime(v)).sort();
        return found.length ? found[0] : "";
    }

    function shiftDeparture(rec) {
        for (const v of [rec.pm_departure, rec.am_departure]) {
            if (v && isClockTime(v)) return v;
        }
        return "";
    }

    function shiftHours(sch, rec) {
        if (!sch) return 0;
        rec = rec || {};
        const tin = shiftArrival(rec) || sch.time_in || "";
        const tout = shiftDeparture(rec) || sch.time_out || "";
        if (!tin || !tout) return 0;
        let hours = (new Date("1970-01-01T" + tout) - new Date("1970-01-01T" + tin)) / 3600000;
        if (hours < 0) hours += 24;     // crossed midnight
        return hours;
    }

    // A continuous shift has no AM session — its session is PM.
    function sessionAffectsShift(sessions) {
        return sessions.includes("PM");
    }

    // Personnel Scheduled to Work: listed on the change AND has their own
    // schedule that weekday -> the change doesn't apply to them.
    function eventExemptsTeacher(event, teacherId, scheduleForDay) {
        if (teacherId === null || teacherId === undefined || !scheduleForDay) return false;
        return (event.worker_ids || []).includes(Number(teacherId));
    }

    // "Included in Total Hours" = Yes credits the teacher's OWN scheduled
    // hours for the affected span (never a fixed number): per session,
    // scan if there is one, otherwise the same schedule/lunch-break
    // fill values the normal DTR rows already auto-fill with.
    function creditedEventHours(scheduleForDay, record, sessions, dateString, event) {

        if (!scheduleForDay) return 0;

        // Attendance rules effective 2026-09-27: the credit is the scheduled span
        // (paid breaks are part of it, nothing is deducted).
        if (dateString && rulesApply(dateString)) {
            return rulesWindowSpan(scheduleForDay, sessions, rulesOpts(scheduleForDay, null, true, event || null));
        }

        // Afternoon / overnight shift: one continuous shift, filed under PM.
        if (isShiftSchedule(scheduleForDay)) {
            return sessionAffectsShift(sessions) ? shiftHours(scheduleForDay, record) : 0;
        }

        const span = (from, to) => (from && to)
            ? Math.max(0, (new Date("1970-01-01T" + to) - new Date("1970-01-01T" + from)) / 3600000)
            : 0;

        let credited = 0;

        if (sessions.includes("AM")) {
            credited += span(
                record.am_arrival || scheduleForDay.time_in,
                record.am_departure || (lunchSettings && lunchSettings.lunch_out)
            );
        }

        if (sessions.includes("PM")) {
            credited += span(
                record.pm_arrival || (lunchSettings && lunchSettings.lunch_in),
                record.pm_departure || scheduleForDay.time_out
            );
        }

        return credited;
    }

    function splitHoursMinutes(decimalHours) {
        let h = Math.floor(decimalHours);
        let m = Math.round((decimalHours - h) * 60);
        if (m === 60) { h++; m = 0; }
        return { h, m };
    }

    // Arrival / departure cells of one continuous shift: they go in the
    // PM In / PM Out columns (the AM columns stay blank). A missing scan
    // is filled from the person's own scheduled time and tagged (Auto);
    // an overnight departure is tagged as the next day.
    function shiftRowCells(sch, rec) {

        rec = rec || {};

        // Only REAL scans are shown: the scheduled time in / out is never
        // printed as an arrival or departure (no "(Auto)" schedule end). A real IN
        // with no real OUT is shown as MISSING OUT.
        const rawIn = shiftArrival(rec);
        const rawOut = shiftDeparture(rec);

        let outCell = cellHTML(rawOut, false);

        if (rawOut && scheduleShape(sch) === "overnight") {
            outCell += '<br><small style="color:#555; font-weight:normal; font-size:6px; line-height:1;">next day</small>';
        }

        if (!rawOut && rawIn) {
            outCell = '<span style="color:#c62828; font-size:7px; font-weight:600;">MISSING OUT</span>';
        }

        return {
            inCell: cellHTML(rawIn, false),
            outCell: outCell
        };
    }

    /* -----------------------------------------------------
       ATTENDANCE RULES effective 2026-09-27 - JS TWIN of
       dtr/attendance-rules.php (dtrDayCalc & friends).
       Change both together; the differential test compares them.
       Real scans are the only attendance; adjustment fields are laid
       over the matching real fields; the schedule never becomes a scan.
       Hours = OUT - IN, paid breaks are never deducted, an OUT after
       the scheduled end is capped at the end until an adjustment
       approves it.
       ----------------------------------------------------- */

    const RULES_EFFECTIVE_DATE = "2026-09-27";
    const RULES_EARLY_DEPARTURE_SECONDS = 1800;
    // Twin of DTR_OVERTIME_TOLERANCE_SECONDS (0 = strict: any OUT after the scheduled end).
    const RULES_OVERTIME_TOLERANCE_SECONDS = 0;
    const RULES_DEFAULT_BREAKS = [
        { time_in: "06:00:00", time_out: "14:00:00", break_start: "11:00:00", break_end: "12:00:00" },
        { time_in: "14:00:00", time_out: "22:00:00", break_start: "17:00:00", break_end: "18:00:00" },
        { time_in: "22:00:00", time_out: "06:00:00", break_start: "02:00:00", break_end: "03:00:00" }
    ];

    function rulesApply(dateString) {
        return dateString >= RULES_EFFECTIVE_DATE;
    }

    function rulesClock(v) {
        if (typeof v !== "string" || !/^\d{2}:\d{2}(:\d{2})?$/.test(v)) return "";
        return v.length === 5 ? v + ":00" : v.slice(0, 8);
    }

    function rulesSecs(v) {
        const c = rulesClock(v);
        if (!c) return null;
        return Number(c.slice(0, 2)) * 3600 + Number(c.slice(3, 5)) * 60 + Number(c.slice(6, 8));
    }

    // Today in Asia/Manila (fixed +08:00, no DST).
    function rulesTodayManila() {
        return new Date(Date.now() + 8 * 3600 * 1000).toISOString().slice(0, 10);
    }

    function rulesEffective(real, adj) {
        const eff = {};
        ["am_arrival", "am_departure", "pm_arrival", "pm_departure"].forEach(col => {
            const a = adj ? rulesClock(adj[col] || "") : "";
            const r = real ? rulesClock(real[col] || "") : "";
            eff[col] = a !== "" ? a : r;
        });
        return eff;
    }

    function rulesInOut(rec, adj) {
        const ins = [rec.am_arrival || "", rec.pm_arrival || ""].filter(v => v !== "").sort();
        const inT = ins.length ? ins[0] : "";
        // a real AM departure is a lunch scan; only an adjustment makes it the OUT
        let outT = rec.pm_departure || "";
        if (outT === "" && (rec.pm_arrival || "") === "" && adj && rulesClock(adj.am_departure || "") !== "") {
            outT = rec.am_departure || "";
        }
        return [inT, outT];
    }

    function rulesOutFromAdj(real, adj) {
        if (!adj) return false;
        if (rulesClock(adj.pm_departure || "") !== "") return true;
        const eff = rulesEffective(real, adj);
        const outT = rulesInOut(eff, adj)[1];
        return outT !== "" && rulesClock(adj.am_departure || "") === outT && (eff.pm_departure || "") === "";
    }

    // The paid break of a person's assigned schedule (twin of dtrBreakForSchedule).
    function rulesBreakFor(sch) {

        if (!sch || !sch.time_in || !sch.time_out) return null;

        const ti = rulesClock(sch.time_in), to = rulesClock(sch.time_out);
        const entries = (lunchSettings && Array.isArray(lunchSettings.schedule_breaks) && lunchSettings.schedule_breaks.length)
            ? lunchSettings.schedule_breaks
            : ((lunchSettings && lunchSettings.schedule_breaks) ? [] : RULES_DEFAULT_BREAKS);

        for (const e of entries) {
            if (rulesClock(e.time_in) === ti && rulesClock(e.time_out) === to) {
                return { start: rulesClock(e.break_start), end: rulesClock(e.break_end), source: "schedule" };
            }
        }

        if (scheduleShape(sch) === "day" && lunchSettings && lunchSettings.lunch_out && lunchSettings.lunch_in) {
            return { start: rulesClock(lunchSettings.lunch_out), end: rulesClock(lunchSettings.lunch_in), source: "default" };
        }

        return null;
    }

    function rulesOpts(sch, session, included, event, noCap) {
        const b = rulesBreakFor(sch);
        return {
            no_cap: !!noCap,
            half_session: session,
            half_included: !!included,
            half_start: (event && event.start_time) || "",
            half_end: (event && event.end_time) || "",
            break_start: b ? b.start : "",
            break_end: b ? b.end : "",
            cutoff_pm: (lunchSettings && lunchSettings.lunch_in) || "13:00:00"
        };
    }

    function rulesDayCalc(sch, real, adj, opts) {

        opts = opts || {};

        const eff = rulesEffective(real, adj);
        const io = rulesInOut(eff, adj);
        const inT = io[0], outT = io[1];

        const half = opts.half_session || null;
        const shape = sch ? scheduleShape(sch) : "day";
        const schedIn = sch ? rulesSecs(sch.time_in || "") : null;
        let schedOut = sch ? rulesSecs(sch.time_out || "") : null;

        if (schedOut !== null && shape === "overnight") schedOut += 86400;

        const r = {
            in: inT, out: outT,
            late: false, early_departure: false,
            overtime: false, overtime_approved: false,
            missing_out: false, complete: false,
            hours: 0
        };

        const inS = rulesSecs(inT);
        let outS = rulesSecs(outT);

        if (inS !== null && schedIn !== null) {
            if (shape === "day" && half === "AM") {
                let ref = rulesSecs(opts.half_end || "");
                if (ref === null) ref = rulesSecs(opts.break_end || "");
                if (ref === null) ref = rulesSecs(opts.cutoff_pm || "13:00:00");
                r.late = inS > ref;
            } else if (shape !== "day" && half === "PM") {
                r.late = false;
            } else {
                r.late = inS > schedIn;
            }
        }

        if (inS === null) return r;

        if (outS === null) {
            r.missing_out = true;
            return r;
        }

        if (outS < inS) {
            if (shape === "overnight") {
                outS += 86400;
            } else {
                r.complete = true;
                return r;
            }
        }

        r.complete = true;
        const approved = rulesOutFromAdj(real, adj);

        let expectedOut = schedOut;
        if (half === "PM" && shape === "day") {
            let he = rulesSecs(opts.half_start || "");
            if (he === null) he = rulesSecs(opts.break_start || "");
            if (he !== null) expectedOut = he;
        }

        let creditOutS = outS;

        if (schedOut !== null) {
            if ((outS - schedOut) > RULES_OVERTIME_TOLERANCE_SECONDS) {
                r.overtime = true;
                r.overtime_approved = approved;
                if (!approved && !opts.no_cap) creditOutS = schedOut;
            }
            if (expectedOut !== null && !r.overtime && (expectedOut - outS) >= RULES_EARLY_DEPARTURE_SECONDS) {
                r.early_departure = true;
            }
        }

        let hours = Math.max(0, creditOutS - inS) / 3600;

        if (half) {
            if (shape !== "day") {
                if (half === "PM") hours = 0;
            } else {
                const bs = rulesSecs(opts.break_start || "");
                const mid = bs !== null ? bs : 43200;
                let ws = rulesSecs(opts.half_start || "");
                let we = rulesSecs(opts.half_end || "");
                if (ws === null || we === null) {
                    if (half === "AM") { ws = schedIn !== null ? schedIn : 0; we = mid; }
                    else { ws = mid; we = schedOut !== null ? schedOut : 86400; }
                }
                const overlap = Math.max(0, Math.min(creditOutS, we) - Math.max(inS, ws));
                hours = Math.max(0, hours - overlap / 3600);
            }
        }

        r.hours = hours;
        return r;
    }

    // Scheduled span of the session(s) - "Included in Total Hours = Yes" credit.
    function rulesWindowSpan(sch, sessions, opts) {

        if (!sch) return 0;

        const tin = rulesSecs(sch.time_in || "");
        const tout = rulesSecs(sch.time_out || "");

        if (tin === null || tout === null) return 0;

        if (scheduleShape(sch) !== "day") {
            if (!sessionAffectsShift(sessions)) return 0;
            return (tout < tin ? tout + 86400 - tin : tout - tin) / 3600;
        }

        const hasAM = sessions.includes("AM");
        const hasPM = sessions.includes("PM");

        if (hasAM && hasPM) return Math.max(0, tout - tin) / 3600;

        let mid = rulesSecs((opts && opts.break_start) || "");
        if (mid === null) mid = 43200;

        let ws = hasAM ? tin : mid;
        let we = hasAM ? mid : tout;

        const es = rulesSecs((opts && opts.half_start) || "");
        const ee = rulesSecs((opts && opts.half_end) || "");
        if (es !== null && ee !== null) {
            ws = Math.max(tin, es);
            we = Math.min(tout, ee);
        }

        return Math.max(0, we - ws) / 3600;
    }

    // Shift / guard personnel = afternoon or overnight schedule, or a schedule whose configured
    // break is not scanned (twin of dtrIsShiftPersonnel). Decided by schedule/break config only.
    function rulesIsShiftPersonnel(sch) {
        if (!sch) return false;
        if (scheduleShape(sch) !== "day") return true;
        const b = rulesBreakFor(sch);
        return !!b && b.source === "schedule";
    }

    function rulesShiftKeys(sch) {
        const shape = scheduleShape(sch);
        if (shape === "day") return { inKey: "amIn", outKey: "amOut" };
        if (shape === "overnight") return { inKey: "pmIn", outKey: "amOut" };
        return { inKey: "pmIn", outKey: "pmOut" };
    }

    // Hours of one day under the new rules (used by every DTR row type).
    function rulesDayHours(sch, real, adj, halfSession, included, event, noCap) {
        const opts = rulesOpts(sch, halfSession, included, event, noCap);
        let h = rulesDayCalc(sch, real, adj, opts).hours;
        if (halfSession && included && sch) h += rulesWindowSpan(sch, [halfSession], opts);
        return h;
    }

    // The four DTR cells of a day under the new rules. Only real scans (and
    // adjustment fields) are shown as times; a regular teacher's paid lunch
    // may show as (Auto) display-only cells; shift personnel show IN / OUT only.
    function rulesCells(sch, real, adj, dateString) {

        const eff = rulesEffective(real, adj);
        const io = rulesInOut(eff, adj);
        const inT = io[0], outT = io[1];
        const shape = sch ? scheduleShape(sch) : "day";
        const blank = "&nbsp;";
        const missing = '<span style="color:#c62828; font-size:7px; font-weight:600;">MISSING OUT</span>';

        // a day is "past" once its shift end has passed (overnight ends the next day)
        let endDate = dateString;
        if (shape === "overnight") {
            const d = new Date(dateString + "T00:00:00Z");
            d.setUTCDate(d.getUTCDate() + 1);
            endDate = d.toISOString().slice(0, 10);
        }
        const isPast = endDate < rulesTodayManila();

        // Shift / guard personnel: an afternoon or overnight schedule, or any schedule whose
        // configured break is NOT scanned. Only the actual IN and OUT are shown - the scheduled
        // end and the paid break never become a departure. Column mapping:
        //   6-2  : IN -> AM Arrival,  OUT -> AM Departure
        //   2-10 : IN -> PM Arrival,  OUT -> PM Departure
        //   10-6 : IN -> PM Arrival,  OUT (next day) -> AM Departure of the SAME shift-start row
        if (rulesIsShiftPersonnel(sch)) {

            const keys = rulesShiftKeys(sch);
            const shiftCells = { amIn: blank, amOut: blank, pmIn: blank, pmOut: blank, inKey: keys.inKey, outKey: keys.outKey };

            shiftCells[keys.inKey] = inT ? cellHTML(inT, false) : blank;

            if (outT) {
                shiftCells[keys.outKey] = cellHTML(outT, false);
                if (shape === "overnight") {
                    shiftCells[keys.outKey] += '<br><small style="color:#555; font-weight:normal; font-size:6px; line-height:1;">next day</small>';
                }
            } else if (inT && isPast) {
                shiftCells[keys.outKey] = missing;
            }

            return shiftCells;
        }

        const cells = {
            amIn: cellHTML(eff.am_arrival, false),
            amOut: cellHTML(eff.am_departure, false),
            pmIn: cellHTML(eff.pm_arrival, false),
            pmOut: cellHTML(eff.pm_departure, false)
        };

        const brk = rulesBreakFor(sch);

        if (brk && brk.source === "default" && inT && outT) {
            const inS = rulesSecs(inT), outS = rulesSecs(outT);
            const bs = rulesSecs(brk.start), be = rulesSecs(brk.end);
            if (inS < bs && outS >= be) {
                if (!eff.am_departure) cells.amOut = cellHTML(brk.start, true);
                if (!eff.pm_arrival) cells.pmIn = cellHTML(brk.end, true);
            }
        }

        if (inT && !outT && isPast) {
            cells.pmOut = missing;
        }

        return cells;
    }

    // Confirmed absences as a fast-lookup set
    const confirmedAbsenceSet = new Set(databaseConfirmedAbsences);

    /* =====================================================
       TABLE ROWS
       ===================================================== */

    let rows = "";
    let totalHours = 0;

    const dayNames = ["sunday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday"];

    for (let day = 1; day <= 31; day++) {

        const dateObj = new Date(year, month, day);

        if (dateObj.getMonth() !== month) break;

        const dayName = dateObj.getDay();
        const dayKey = dayNames[dayName];

        const dateString =
            `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;

        /* =================================================
           1. TEACHER LEAVE — highest priority
           ================================================= */

        let onLeave = null;

        for (const leave of leaveRemarks) {
            if (dateString >= leave.date_from && dateString <= leave.date_to) {
                onLeave = leave;
                break;
            }
        }

        if (onLeave) {

            let leaveText = (onLeave.leave_type || "ON LEAVE").toUpperCase();

            if (onLeave.reason) {
                leaveText += `<br><small>${onLeave.reason}</small>`;
            }

            rows += `
                <tr class="special-day-row">
                    <td>${day}</td>
                    <td colspan="6"><span>${leaveText}</span></td>
                </tr>
            `;

            continue;
        }

        /* =================================================
           2. ATTENDANCE ADJUSTMENT
           ================================================= */

        const adjustment = adjustmentMap[dateString];

        if (adjustment) {

            const amIn = adjustment.am_arrival || "";
            const amOut = adjustment.am_departure || "";
            const pmIn = adjustment.pm_arrival || "";
            const pmOut = adjustment.pm_departure || "";

            const hasAdjustedTimes = amIn || amOut || pmIn || pmOut;

            // No times at all (e.g. Official Business) — show as a label row
            if (!hasAdjustedTimes) {

                let adjText = (adjustment.adjustment_type || "ADJUSTED").toUpperCase();

                if (adjustment.remarks) {
                    adjText += `<br><small>${adjustment.remarks}</small>`;
                }

                rows += `
                    <tr class="special-day-row">
                        <td>${day}</td>
                        <td colspan="6"><span>${adjText}</span></td>
                    </tr>
                `;

                continue;
            }

            // Has times — auto-fill any still-missing field from schedule/lunch,
            // same as raw attendance, so partial adjustments compute correct hours.
            const scheduleForDay = teacherSchedule[dayKey] || null;

            // Attendance rules effective 2026-09-27: the adjustment's non-empty
            // fields are laid over the REAL scans (nothing comes from the schedule).
            // NARROW historical correction: shift / guard personnel on dates before the effective
            // date also use their real IN/OUT only (never the scheduled end); legacy uncapped math.
            if (rulesApply(dateString) || rulesIsShiftPersonnel(scheduleForDay)) {

                const adjReal = teacher.records.find(x => x.date === dateString) || {};
                const adjDaily = rulesDayHours(scheduleForDay, adjReal, adjustment, null, false, null, !rulesApply(dateString));
                totalHours += adjDaily;

                const adjCells = rulesCells(scheduleForDay, adjReal, adjustment, dateString);
                const adjTime = splitHoursMinutes(adjDaily);

                rows += `
                    <tr title="${(adjustment.adjustment_type || "Adjusted") + (adjustment.remarks ? " - " + adjustment.remarks : "")}">
                        <td>${day}</td>
                        <td>${adjCells.amIn}</td>
                        <td>${adjCells.amOut}</td>
                        <td>${adjCells.pmIn}</td>
                        <td>${adjCells.pmOut}</td>
                        <td>${adjTime.h}</td>
                        <td>${adjTime.m}</td>
                    </tr>
                `;

                continue;
            }

            // Afternoon / overnight shift: one continuous shift.
            if (isShiftSchedule(scheduleForDay)) {

                const shiftDaily = shiftHours(scheduleForDay, adjustment);
                totalHours += shiftDaily;

                const shiftCells = shiftRowCells(scheduleForDay, adjustment);
                const shiftTime = splitHoursMinutes(shiftDaily);

                rows += `
                    <tr title="${(adjustment.adjustment_type || "Adjusted") + (adjustment.remarks ? " - " + adjustment.remarks : "")}">
                        <td>${day}</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>${shiftCells.inCell}</td>
                        <td>${shiftCells.outCell}</td>
                        <td>${shiftTime.h}</td>
                        <td>${shiftTime.m}</td>
                    </tr>
                `;

                continue;
            }

            let adjAmIn = amIn;
            let adjAmOut = amOut;
            let adjPmIn = pmIn;
            let adjPmOut = pmOut;

            let adjAmInAuto = false;
            let adjAmOutAuto = false;
            let adjPmInAuto = false;
            let adjPmOutAuto = false;

            if (!adjAmIn && scheduleForDay && scheduleForDay.time_in) {
                adjAmIn = scheduleForDay.time_in;
                adjAmInAuto = true;
            }

            if (!adjPmOut && scheduleForDay && scheduleForDay.time_out) {
                adjPmOut = scheduleForDay.time_out;
                adjPmOutAuto = true;
            }

            if (!adjAmOut && lunchSettings && lunchSettings.lunch_out) {
                adjAmOut = lunchSettings.lunch_out;
                adjAmOutAuto = true;
            }

            if (!adjPmIn && lunchSettings && lunchSettings.lunch_in) {
                adjPmIn = lunchSettings.lunch_in;
                adjPmInAuto = true;
            }

            let amHours = 0;

            if (adjAmIn && adjAmOut) {
                amHours = (new Date("1970-01-01T" + adjAmOut) - new Date("1970-01-01T" + adjAmIn)) / 3600000;
            }

            let pmHours = 0;

            if (adjPmIn && adjPmOut) {
                pmHours = (new Date("1970-01-01T" + adjPmOut) - new Date("1970-01-01T" + adjPmIn)) / 3600000;
            }

            const daily = amHours + pmHours;
            totalHours += daily;

            const hours = Math.floor(daily);
            const minutes = Math.round((daily % 1) * 60);

            const tooltip =
                (adjustment.adjustment_type || "Adjusted") +
                (adjustment.remarks ? " - " + adjustment.remarks : "");

            rows += `
                <tr title="${tooltip}">
                    <td>${day}</td>
                    <td>${cellHTML(adjAmIn, adjAmInAuto)}</td>
                    <td>${cellHTML(adjAmOut, adjAmOutAuto)}</td>
                    <td>${cellHTML(adjPmIn, adjPmInAuto)}</td>
                    <td>${cellHTML(adjPmOut, adjPmOutAuto)}</td>
                    <td>${hours}</td>
                    <td>${minutes}</td>
                </tr>
            `;

            continue;
        }

        /* =================================================
           3. MANUAL REMARK (Absent / Other)
           ================================================= */

        const specialRemark = remarkMap[dateString];

        /* -------------------------------------------------
           OFFICIAL BUSINESS (individual teacher remark)
           Unlike Absent/Other, the row is NOT collapsed: the
           teacher's real scans stay visible, and the remark fills
           the cells of the affected session(s) that have no scan.
           Hours follow this teacher's own schedule (never a fixed
           number), through the same creditedEventHours() the school
           schedule changes use:
             Included = Yes -> affected span credited
             Included = No  -> affected span adds nothing
           Half Day: only the chosen AM/PM session is affected; the
           other session behaves like a normal day.
           ------------------------------------------------- */

        if (specialRemark && specialRemark.remark_type === "Official Business") {

            const obSchedule = teacherSchedule[dayKey] || null;

            // Not scheduled that day (Sunday, unscheduled Saturday, a
            // part-time off-day): nothing to credit — label only.
            if (!obSchedule) {

                rows += `
                    <tr class="special-day-row">
                        <td>${day}</td>
                        <td colspan="6"><span>OFFICIAL BUSINESS</span></td>
                    </tr>
                `;

                continue;
            }

            const obRecord = teacher.records.find(x => x.date === dateString) || {};

            const obIncluded = Number(specialRemark.included_in_total_hours) === 1;

            const obHalfDay =
                specialRemark.duration === "Half Day" &&
                (specialRemark.half_day_session === "AM" || specialRemark.half_day_session === "PM");

            const obSessions = obHalfDay ? [specialRemark.half_day_session] : ["AM", "PM"];

            const obLabel =
                '<span style="color:#1565c0; font-size:7px; font-weight:600;">OFFICIAL BUSINESS</span>';

            const obHasAnyScan = !!(
                obRecord.am_arrival || obRecord.am_departure ||
                obRecord.pm_arrival || obRecord.pm_departure
            );

            const obSpan = (from, to) => (from && to)
                ? Math.max(0, (new Date("1970-01-01T" + to) - new Date("1970-01-01T" + from)) / 3600000)
                : 0;

            // Attendance rules effective 2026-09-27
            if (rulesApply(dateString)) {

                const obCells = rulesCells(obSchedule, obRecord, null, dateString);
                const blankCell = "&nbsp;";
                const label = (html) => (html === blankCell ? obLabel : html);
                const obShift = rulesIsShiftPersonnel(obSchedule);

                if (obShift) {

                    if (sessionAffectsShift(obSessions)) {
                        obCells[obCells.inKey] = label(obCells[obCells.inKey]);
                        obCells[obCells.outKey] = label(obCells[obCells.outKey]);
                    }

                } else {

                    if (obSessions.includes("PM")) {
                        obCells.pmIn = label(obCells.pmIn);
                        obCells.pmOut = label(obCells.pmOut);
                    }

                    if (obSessions.includes("AM")) {
                        obCells.amIn = label(obCells.amIn);
                        obCells.amOut = label(obCells.amOut);
                    }
                }

                let obNewHours;

                if (obSessions.length === 2) {
                    obNewHours = obIncluded
                        ? rulesWindowSpan(obSchedule, ["AM", "PM"], rulesOpts(obSchedule, null, obIncluded, null))
                        : 0;
                } else {
                    obNewHours = rulesDayHours(obSchedule, obRecord, null, obSessions[0], obIncluded, null);
                }

                totalHours += obNewHours;
                const obNewTime = splitHoursMinutes(obNewHours);

                rows += `
                    <tr>
                        <td>${day}</td>
                        <td>${obCells.amIn}</td>
                        <td>${obCells.amOut}</td>
                        <td>${obCells.pmIn}</td>
                        <td>${obCells.pmOut}</td>
                        <td>${obNewTime.h}</td>
                        <td>${obNewTime.m}</td>
                    </tr>
                `;

                continue;
            }

            // Afternoon / overnight shift: no AM session — the whole shift is
            // the "PM" one. A PM (or Whole Day) remark covers it; an AM Half
            // Day remark leaves the shift as a normal day.
            if (isShiftSchedule(obSchedule)) {

                let shiftIn = "&nbsp;";
                let shiftOut = "&nbsp;";
                let shiftH = 0;

                if (sessionAffectsShift(obSessions)) {

                    const rawIn = shiftArrival(obRecord);
                    const rawOut = shiftDeparture(obRecord);

                    shiftIn = rawIn ? cellHTML(rawIn, false) : obLabel;
                    shiftOut = rawOut ? cellHTML(rawOut, false) : obLabel;
                    shiftH = obIncluded ? shiftHours(obSchedule, obRecord) : 0;

                } else if (obHasAnyScan) {

                    const cells = shiftRowCells(obSchedule, obRecord);
                    shiftIn = cells.inCell;
                    shiftOut = cells.outCell;
                    shiftH = shiftHours(obSchedule, obRecord);

                } else if (dateString < todayString) {

                    shiftIn = '<span style="color:#757575; font-size:7px; font-weight:600;">PENDING REVIEW</span>';
                }

                totalHours += shiftH;
                const shiftTime = splitHoursMinutes(shiftH);

                rows += `
                    <tr>
                        <td>${day}</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>${shiftIn}</td>
                        <td>${shiftOut}</td>
                        <td>${shiftTime.h}</td>
                        <td>${shiftTime.m}</td>
                    </tr>
                `;

                continue;
            }

            // Builds one session's two cells + its hours.
            const obSession = (session) => {

                const isAM = session === "AM";
                const rawIn = (isAM ? obRecord.am_arrival : obRecord.pm_arrival) || "";
                const rawOut = (isAM ? obRecord.am_departure : obRecord.pm_departure) || "";

                // Affected session: actual scans shown as-is, the remark
                // stands in for whatever was not scanned.
                if (obSessions.includes(session)) {
                    return {
                        inCell: rawIn ? cellHTML(rawIn, false) : obLabel,
                        outCell: rawOut ? cellHTML(rawOut, false) : obLabel,
                        hours: obIncluded ? creditedEventHours(obSchedule, obRecord, [session]) : 0
                    };
                }

                // Other session of a Half Day: a normal day, same fill
                // rules as any row with at least one scan.
                if (!obHasAnyScan) {
                    return {
                        inCell: dateString < todayString
                            ? '<span style="color:#757575; font-size:7px; font-weight:600;">PENDING REVIEW</span>'
                            : "&nbsp;",
                        outCell: "&nbsp;",
                        hours: 0
                    };
                }

                const fillIn = isAM ? obSchedule.time_in : (lunchSettings && lunchSettings.lunch_in);
                const fillOut = isAM ? (lunchSettings && lunchSettings.lunch_out) : obSchedule.time_out;

                const inTime = rawIn || fillIn || "";
                const outTime = rawOut || fillOut || "";

                return {
                    inCell: cellHTML(inTime, !rawIn && !!fillIn),
                    outCell: cellHTML(outTime, !rawOut && !!fillOut),
                    hours: obSpan(inTime, outTime)
                };
            };

            const am = obSession("AM");
            const pm = obSession("PM");

            const obDaily = am.hours + pm.hours;
            totalHours += obDaily;

            const obTime = splitHoursMinutes(obDaily);

            rows += `
                <tr>
                    <td>${day}</td>
                    <td>${am.inCell}</td>
                    <td>${am.outCell}</td>
                    <td>${pm.inCell}</td>
                    <td>${pm.outCell}</td>
                    <td>${obTime.h}</td>
                    <td>${obTime.m}</td>
                </tr>
            `;

            continue;
        }

        if (specialRemark) {

            let displayRemark = specialRemark.remark_type;

            if (specialRemark.remark_type === "Other" && specialRemark.remark_text) {
                displayRemark = specialRemark.remark_text;
            }

            rows += `
                <tr class="special-day-row">
                    <td>${day}</td>
                    <td colspan="6"><span>${displayRemark.toUpperCase()}</span></td>
                </tr>
            `;

            continue;
        }

               /* =================================================
           4. SCHOOL EVENT — applies to everyone (date range)
           ================================================= */

        let matchedEvent = null;

        for (const event of holidayRanges) {
            if (dateString >= event.date_from && dateString <= event.date_to) {

                // Scheduled to work through this change: it doesn't apply
                // to this person — the day is a normal working day.
                if (eventExemptsTeacher(event, teacher.id, teacherSchedule[dayKey] || null)) {
                    continue;
                }

                matchedEvent = event;
                break;
            }
        }

        // Half Day events only affect ONE session (AM or PM) — they
        // fall through to the normal per-day logic below instead of
        // collapsing the whole row, so only the affected session's two
        // cells get overridden. Whole Day events are unchanged: they
        // still collapse the entire row exactly as before.
        let halfDayNoClassesSession = null;
        let halfDayNoClassesLabel = '';

        // Super Admin's "Included in Total Hours" choice for this event —
        // the source of truth for whether it contributes to total hours.
        // The remark is always displayed either way.
        const eventIncluded = !!matchedEvent && Number(matchedEvent.included_in_total_hours) === 1;

        if (matchedEvent && matchedEvent.duration !== "Half Day") {

            let eventText = (matchedEvent.event_name || matchedEvent.event_type).toUpperCase();

            if (matchedEvent.remark) {
                eventText += `<br><small>${matchedEvent.remark}</small>`;
            }

            // Included = Yes: credit this teacher's own scheduled day.
            // Included = No (or teacher not scheduled that day): 0 hours,
            // exactly as before.
            const creditedWholeDay = eventIncluded
                ? creditedEventHours(
                    teacherSchedule[dayKey] || null,
                    teacher.records.find(x => x.date === dateString) || {},
                    ["AM", "PM"],
                    dateString,
                    null
                )
                : 0;

            if (creditedWholeDay > 0) {

                totalHours += creditedWholeDay;
                const credit = splitHoursMinutes(creditedWholeDay);

                rows += `
                    <tr class="special-day-row">
                        <td>${day}</td>
                        <td colspan="4"><span>${eventText}</span></td>
                        <td>${credit.h}</td>
                        <td>${credit.m}</td>
                    </tr>
                `;

            } else {

                rows += `
                    <tr class="special-day-row">
                        <td>${day}</td>
                        <td colspan="6"><span>${eventText}</span></td>
                    </tr>
                `;
            }

            continue;
        }

        if (matchedEvent && matchedEvent.duration === "Half Day" && matchedEvent.start_time) {
            // The stored start_time/end_time represent the Half Day
            // range itself — i.e. the session with NO classes — not the
            // session that has classes. Noon is the AM/PM dividing line:
            // a start_time before noon falls in the morning session, so
            // AM is the one with no classes; a start_time at/after noon
            // falls in the afternoon session, so PM has no classes.
            halfDayNoClassesSession = (matchedEvent.start_time < "12:00:00") ? "AM" : "PM";
            halfDayNoClassesLabel = matchedEvent.remark
                || ((matchedEvent.event_name || matchedEvent.event_type).toUpperCase() + " - NO CLASSES");
        }

        // Renders either the normal cell content, or the "No Classes"
        // label if this cell's session is the one a Half Day event
        // affects — used at every row-rendering point below.
        function halfDayCell(defaultHTML, session) {
            if (halfDayNoClassesSession === session) {
                return `<span style="color:#a3720f; font-size:7px; font-weight:600;">${halfDayNoClassesLabel}</span>`;
            }
            return defaultHTML;
        }

        /* =================================================
           5. WEEKENDS — Sunday always off; Saturday only
           shows as off if THIS teacher has no Saturday
           schedule. If they do, it falls through and gets
           treated like any other working day below.
           ================================================= */

        if (dayName === 0) {

            rows += `
                <tr>
                    <td>${day}</td>
                    <td colspan="6">Sun</td>
                </tr>
            `;

            continue;
        }

        if (dayName === 6 && !teacherSchedule[dayKey]) {

            rows += `
                <tr>
                    <td>${day}</td>
                    <td colspan="6">Sat</td>
                </tr>
            `;

            continue;
        }

        /* =================================================
           6. CONFIRMED ABSENT
           ================================================= */

        if (confirmedAbsenceSet.has(dateString)) {

            rows += `
                <tr class="special-day-row">
                    <td>${day}</td>
                    <td colspan="6"><span style="color:#c62828;">ABSENT</span></td>
                </tr>
            `;

            continue;
        }

        /* =================================================
           7. RAW ATTENDANCE / PENDING REVIEW / AUTO-FILL
           ================================================= */

        let r = teacher.records.find(x => x.date === dateString) || {};

        const scheduleForDay = teacherSchedule[dayKey] || null;

        const rawAmIn = r.am_arrival || "";
        const rawAmOut = r.am_departure || "";
        const rawPmIn = r.pm_arrival || "";
        const rawPmOut = r.pm_departure || "";

        const hasAnyScan = !!(rawAmIn || rawAmOut || rawPmIn || rawPmOut);

        // ---------- No scans at all ----------
        if (!hasAnyScan) {

            // Not scheduled to work this day (e.g. Part-Time) — leave blank, never Pending/Absent
            if (!scheduleForDay) {

                rows += `
                    <tr>
                        <td>${day}</td>
                        <td>${halfDayCell("&nbsp;", "AM")}</td>
                        <td>${halfDayCell("&nbsp;", "AM")}</td>
                        <td>${halfDayCell("&nbsp;", "PM")}</td>
                        <td>${halfDayCell("&nbsp;", "PM")}</td>
                        <td></td>
                        <td></td>
                    </tr>
                `;

                continue;
            }

            // Half Day + Included in Total Hours = Yes: the affected
            // session is credited from this teacher's own schedule even
            // without a scan; the other session stays unrecorded.
            if (halfDayNoClassesSession && eventIncluded) {

                const creditedSession = creditedEventHours(scheduleForDay, {}, [halfDayNoClassesSession], dateString, matchedEvent);
                totalHours += creditedSession;
                const credit = splitHoursMinutes(creditedSession);

                const otherCell = dateString < todayString
                    ? '<span style="color:#757575; font-size:7px; font-weight:600;">PENDING REVIEW</span>'
                    : "&nbsp;";

                rows += `
                    <tr>
                        <td>${day}</td>
                        <td>${halfDayCell(otherCell, "AM")}</td>
                        <td>${halfDayCell("&nbsp;", "AM")}</td>
                        <td>${halfDayCell(otherCell, "PM")}</td>
                        <td>${halfDayCell("&nbsp;", "PM")}</td>
                        <td>${credit.h}</td>
                        <td>${credit.m}</td>
                    </tr>
                `;

                continue;
            }

            // Day already passed, scheduled, nothing recorded yet — Pending Review
            if (dateString < todayString) {

                rows += `
                    <tr class="special-day-row">
                        <td>${day}</td>
                        <td colspan="6"><span style="color:#757575;">PENDING REVIEW</span></td>
                    </tr>
                `;

                continue;
            }

            // Today or future — nothing to evaluate yet
            rows += `
                <tr>
                    <td>${day}</td>
                    <td>${halfDayCell("&nbsp;", "AM")}</td>
                    <td>${halfDayCell("&nbsp;", "AM")}</td>
                    <td>${halfDayCell("&nbsp;", "PM")}</td>
                    <td>${halfDayCell("&nbsp;", "PM")}</td>
                    <td></td>
                    <td></td>
                </tr>
            `;

            continue;
        }

        // ---------- Attendance rules effective 2026-09-27 ----------
        // Real scans only: hours = OUT - IN (paid breaks included, OUT capped at the
        // scheduled end until an adjustment approves it); nothing is filled from the
        // schedule. A Half Day change removes the affected window (credited back when
        // "Included in Total Hours" = Yes).

        // (also used, uncapped, for shift / guard personnel on historical dates)
        if (rulesApply(dateString) || rulesIsShiftPersonnel(scheduleForDay)) {

            const nrHours = rulesDayHours(scheduleForDay, r, null, halfDayNoClassesSession, eventIncluded, matchedEvent, !rulesApply(dateString));
            totalHours += nrHours;

            const nrCells = rulesCells(scheduleForDay, r, null, dateString);
            const nrTime = splitHoursMinutes(nrHours);

            // A shift's IN/OUT columns all belong to the shift's own session
            // (AM for a 6-2 day shift, PM for afternoon / overnight).
            const nrShift = rulesIsShiftPersonnel(scheduleForDay);
            const shiftSession = (nrShift && scheduleShape(scheduleForDay) === "day") ? "AM" : "PM";
            const wrapCell = (key, natural) => halfDayCell(nrCells[key], nrShift && (key === nrCells.inKey || key === nrCells.outKey) ? shiftSession : natural);

            rows += `
                <tr>
                    <td>${day}</td>
                    <td>${wrapCell("amIn", "AM")}</td>
                    <td>${wrapCell("amOut", "AM")}</td>
                    <td>${wrapCell("pmIn", "PM")}</td>
                    <td>${wrapCell("pmOut", "PM")}</td>
                    <td>${nrTime.h}</td>
                    <td>${nrTime.m}</td>
                </tr>
            `;

            continue;
        }

        // ---------- Afternoon / overnight shift: one continuous shift ----------
        // (no AM / lunch / PM split; an affected Half Day PM change excludes
        // the whole shift unless it is marked Included in Total Hours)

        if (isShiftSchedule(scheduleForDay)) {

            let shiftH = shiftHours(scheduleForDay, r);

            if (halfDayNoClassesSession === "PM" && !eventIncluded) {
                shiftH = 0;
            }

            totalHours += shiftH;

            const shiftCells = shiftRowCells(scheduleForDay, r);
            const shiftTime = splitHoursMinutes(shiftH);

            rows += `
                <tr>
                    <td>${day}</td>
                    <td>${halfDayCell("&nbsp;", "AM")}</td>
                    <td>${halfDayCell("&nbsp;", "AM")}</td>
                    <td>${halfDayCell(shiftCells.inCell, "PM")}</td>
                    <td>${halfDayCell(shiftCells.outCell, "PM")}</td>
                    <td>${shiftTime.h}</td>
                    <td>${shiftTime.m}</td>
                </tr>
            `;

            continue;
        }

        // ---------- Has at least one real scan — fill gaps, mark Auto ----------

        let amIn = rawAmIn;
        let amOut = rawAmOut;
        let pmIn = rawPmIn;
        let pmOut = rawPmOut;

        let amInAuto = false;
        let amOutAuto = false;
        let pmInAuto = false;
        let pmOutAuto = false;

        if (!amIn && scheduleForDay && scheduleForDay.time_in) {
            amIn = scheduleForDay.time_in;
            amInAuto = true;
        }

        if (!pmOut && scheduleForDay && scheduleForDay.time_out) {
            pmOut = scheduleForDay.time_out;
            pmOutAuto = true;
        }

        if (!amOut && lunchSettings && lunchSettings.lunch_out) {
            amOut = lunchSettings.lunch_out;
            amOutAuto = true;
        }

        if (!pmIn && lunchSettings && lunchSettings.lunch_in) {
            pmIn = lunchSettings.lunch_in;
            pmInAuto = true;
        }

        let amHours = 0;

        if (amIn && amOut) {
            amHours = (new Date("1970-01-01T" + amOut) - new Date("1970-01-01T" + amIn)) / 3600000;
        }

        let pmHours = 0;

        if (pmIn && pmOut) {
            pmHours = (new Date("1970-01-01T" + pmOut) - new Date("1970-01-01T" + pmIn)) / 3600000;
        }

        // Affected Half Day session is excluded from total hours unless
        // the Super Admin marked the event "Included in Total Hours".
        if (halfDayNoClassesSession === "AM" && !eventIncluded) {
            amHours = 0;
        }

        if (halfDayNoClassesSession === "PM" && !eventIncluded) {
            pmHours = 0;
        }

        const daily = amHours + pmHours;
        totalHours += daily;

        const hours = Math.floor(daily);
        const minutes = Math.round((daily % 1) * 60);

        rows += `
            <tr>
                <td>${day}</td>
                <td>${halfDayCell(cellHTML(amIn, amInAuto), "AM")}</td>
                <td>${halfDayCell(cellHTML(amOut, amOutAuto), "AM")}</td>
                <td>${halfDayCell(cellHTML(pmIn, pmInAuto), "PM")}</td>
                <td>${halfDayCell(cellHTML(pmOut, pmOutAuto), "PM")}</td>
                <td>${hours}</td>
                <td>${minutes}</td>
            </tr>
        `;
    }

    /* =====================================================
       TOTAL HOURS
       ===================================================== */

    let totalH = Math.floor(totalHours);
    let totalM = Math.round((totalHours - totalH) * 60);

    if (totalM === 60) {
        totalH++;
        totalM = 0;
    }

    /* =====================================================
       BUILD CONTAINER (the exact same markup every DTR print
       has always used — Generate All DTR reuses this verbatim
       per teacher via returnContainerOnly)
       ===================================================== */

    /* =====================================================
       DISPLAY ONLY - visible column labels / shift layout.
       Underlying am_/pm_ values, hours and rules are untouched.
       Regular teachers: A.M. / P.M. groups of CLOCK IN | CLOCK OUT.
       Guard / shift personnel (every scheduled day is a shift):
       a single CLOCK IN | CLOCK OUT pair per row (the real IN and the
       real OUT already sit in the mapped cells; the other pair is blank).
       ===================================================== */

    const scheduledDaySchedules = Object.values(teacherSchedule || {});
    const shiftLayout = scheduledDaySchedules.length > 0
        && scheduledDaySchedules.every(s => rulesIsShiftPersonnel(s));

    if (shiftLayout) {

        const isBlankCell = c => c.trim() === "" || c.trim() === "&nbsp;";

        rows = rows.replace(/<tr([^>]*)>([\s\S]*?)<\/tr>/g, (whole, attrs, inner) => {

            const tds = [...inner.matchAll(/<td([^>]*)>([\s\S]*?)<\/td>/g)];

            // Day | IN | OUT | IN | OUT | Hours | Minutes  ->  Day | IN | OUT | Hours | Minutes
            if (tds.length === 7 && !tds.some(t => /colspan/.test(t[1]))) {
                const c = i => tds[i][2];
                const clockIn = !isBlankCell(c(1)) ? c(1) : c(3);
                const clockOut = !isBlankCell(c(2)) ? c(2) : c(4);
                return `<tr${attrs}><td${tds[0][1]}>${c(0)}</td><td${tds[1][1]}>${clockIn}</td><td${tds[2][1]}>${clockOut}</td><td${tds[5][1]}>${c(5)}</td><td${tds[6][1]}>${c(6)}</td></tr>`;
            }

            // label rows span the four time columns: 4 -> 2 (6 -> 4 when they also span the hours)
            return `<tr${attrs}>${inner.replace(/colspan="(\d+)"/g, (x, n) => Number(n) >= 4 ? `colspan="${Number(n) - 2}"` : x)}</tr>`;
        });
    }

    const dtrHeaderHTML = shiftLayout
        ? `
                <tr>
                    <th rowspan="2">Day</th>
                    <th rowspan="2">CLOCK IN</th>
                    <th rowspan="2">CLOCK OUT</th>
                    <th colspan="2">Total Hours</th>
                </tr>
                <tr>
                    <th>Hours</th><th>Minutes</th>
                </tr>`
        : `
                <tr>
                    <th rowspan="2">Day</th>
                    <th colspan="2">A.M.</th>
                    <th colspan="2">P.M.</th>
                    <th colspan="2">Total Hours</th>
                </tr>
                <tr>
                    <th>CLOCK IN</th><th>CLOCK OUT</th>
                    <th>CLOCK IN</th><th>CLOCK OUT</th>
                    <th>Hours</th><th>Minutes</th>
                </tr>`;

    const containerHTML = `
        <div class="container">
            <div class="print-logo-block">
                <img src="${appUrl('img/logo.png')}" alt="School Logo" class="print-logo-img">
                <div class="print-school-name">San Jose Community High School</div>
            </div>
            <div class="header">
                <div>Civil Service Form No. 48</div>
                <div>Emp. No. __________</div>
            </div>
            <div class="title">DAILY TIME RECORD</div>
            <div class="subtitle">-----o0o-----</div>
            <div class="name-line">${teacher.fullname}</div>
            <div class="details">
                <div>For the month of <b>${monthYear}</b></div>
                <div>Official hours for arrival and departure</div>
            </div>
            <div class="details">
                <div>Regular days <b>${teacher.schedule_session || ""}</b></div>
                <div>Official hours: <b>${scheduleSummary}</b></div>
            </div>
            <table>
                ${dtrHeaderHTML}
                ${rows}
                <tr>
                    <td colspan="${shiftLayout ? 3 : 5}">Total</td>
                    <td>${totalH}</td>
                    <td>${totalM}</td>
                </tr>
            </table>
            <div class="legend">
                (Auto) — time auto-filled from schedule/lunch break, teacher did not scan this specific field.
            </div>
            <div class="footer">
                <p>I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>

                <div class="signature-row">

                    <div class="signature-block">
                        <div class="signature-line"></div>
                        <div class="signature-name">${teacher.fullname}</div>
                        <div class="signature-title">Employee's Signature</div>
                    </div>

                    <div class="signature-block">
                        <div class="signature-line"></div>
                        <div class="signature-name">&nbsp;</div>
                        <div class="signature-title">Principal / In-Charge Signature</div>
                    </div>

                </div>

                <div class="right" style="margin-top: 12px;"><b>Station: ASTURIAS ES</b></div>
            </div>
        </div>
    `;

    if (returnContainerOnly) {
        return containerHTML;
    }

    /* =====================================================
       PRINT IN PLACE (no new tab/window)
       ===================================================== */

    const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>DTR - ${teacher.fullname}</title>
            <style>${DTR_STYLE_BLOCK}</style>
        </head>
        <body>
            ${containerHTML}
        </body>
        </html>
    `;

    printHTMLDocument(printHTML);
}

/* =========================================================
   GENERATE ALL DTR
   Reuses printDTRWithRemarks' exact container-building logic
   per teacher (returnContainerOnly=true) instead of a separate
   template. Bulk mode has no interactive remarks modal, so it
   uses whatever remarks are already saved in dtr_remarks for
   each teacher/month (same as re-printing an individual DTR
   without changing any remarks).
   ========================================================= */

async function printAllDTR(teacherReports, monthStart, monthEnd) {

    if (!teacherReports || teacherReports.length === 0) {
        showMessageModal("No personnel found for this month.", { title: "Nothing to Print" });
        return;
    }

    const selected = document.querySelector(".monthPicker")?.value;
    const now = new Date();
    let year = now.getFullYear();
    let month = now.getMonth();

    if (selected) {
        const parts = selected.split("-");
        year = parseInt(parts[0]);
        month = parseInt(parts[1]) - 1;
    }

    let allContainers = "";

    for (const teacher of teacherReports) {

        try {

            const [leavesRes, adjustmentsRes, holidaysRes, absencesRes, scheduleRes, lunchRes, remarksRes] = await Promise.all([
                fetch(`${appUrl('data/teacher-leaves')}?teacher_id=${teacher.id}`),
                fetch(`${appUrl('data/teacher-adjustments')}?teacher_id=${teacher.id}&start=${monthStart}&end=${monthEnd}`),
                fetch(appUrl('data/school-events')),
                fetch(`${appUrl('data/confirmed-absences')}?teacher_id=${teacher.id}&start=${monthStart}&end=${monthEnd}`),
                fetch(`${appUrl('data/teacher-schedule')}?teacher_id=${teacher.id}`),
                fetch(appUrl('data/recording-status')),
                fetch(`${appUrl('data/dtr-remarks')}?teacher_id=${teacher.id}&start=${monthStart}&end=${monthEnd}`)
            ]);

            const [leavesData, adjustmentsData, holidaysData, absencesData, scheduleData, lunchData, remarksData] = await Promise.all([
                leavesRes.json(), adjustmentsRes.json(), holidaysRes.json(), absencesRes.json(), scheduleRes.json(), lunchRes.json(), remarksRes.json()
            ]);

            const databaseLeaves = leavesData.success ? (leavesData.leaves || []) : [];
            const databaseAdjustments = adjustmentsData.success ? (adjustmentsData.adjustments || []) : [];
            const databaseHolidays = holidaysData.success ? (holidaysData.events || []) : [];
            const databaseConfirmedAbsences = absencesData.success ? (absencesData.absences || []) : [];
            const teacherSchedule = scheduleData.success ? (scheduleData.schedule || {}) : {};
            const lunchSettings = {
                lunch_out: lunchData.success ? (lunchData.lunch_out || null) : null,
                lunch_in: lunchData.success ? (lunchData.lunch_in || null) : null,
                schedule_breaks: lunchData.success ? (lunchData.schedule_breaks || []) : []
            };
            const remarks = remarksData.success ? (remarksData.remarks || []) : [];

            const container = printDTRWithRemarks(
                teacher.id,
                remarks,
                databaseLeaves,
                databaseAdjustments,
                databaseHolidays,
                databaseConfirmedAbsences,
                teacherSchedule,
                lunchSettings,
                teacher,
                year,
                month,
                true
            );

            allContainers += `<div class="dtr-page-break">${container}</div>`;

        } catch (error) {
            console.error("Generate All DTR error for teacher", teacher.id, error);
        }
    }

    const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>All DTR</title>
            <style>${DTR_STYLE_BLOCK}</style>
        </head>
        <body>
            ${allContainers}
        </body>
        </html>
    `;

    printHTMLDocument(printHTML);
}
