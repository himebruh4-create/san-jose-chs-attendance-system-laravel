/* ATTENDANCE ADJUSTMENTS, PENDING REVIEW, REJECTED SCAN LOG (Super Admin)
   Moved from the inline script of superadmin/attendance-adjustments.php. */


        document.addEventListener(
            "DOMContentLoaded",
            function () {

                loadAttendanceAdjustments();

            }
        );



        /* ==============================
           ESCAPE HTML
        ============================== */

        function escapeHTML(value) {

            const div =
                document.createElement("div");

            div.textContent =
                value ?? "";

            return div.innerHTML;

        }



        /* ==============================
           LOAD ADJUSTMENTS
        ============================== */

        async function loadAttendanceAdjustments() {

            const tbody =
                document.getElementById(
                    "adjustmentsTableBody"
                );


            try {

                const response =
                    await fetch(
                        appUrl('data/adjustments')
                    );


                if (!response.ok) {

                    throw new Error(
                        "HTTP error: " +
                        response.status
                    );

                }


                const result =
                    await response.json();


                console.log(
                    "Attendance Adjustments:",
                    result
                );


                if (!result.success) {

                    tbody.innerHTML = `

                        <tr>

                            <td
                                colspan="5"
                                class="empty-state"
                            >

                                Unable to load attendance adjustments.

                            </td>

                        </tr>

                    `;

                    return;

                }


                const adjustments =
                    result.adjustments || [];


                /* ==========================
                   NO RECORDS
                ========================== */

                if (
                    adjustments.length === 0
                ) {

                    tbody.innerHTML = `

                        <tr>

                            <td
                                colspan="5"
                                class="empty-state"
                            >

                                <strong>
                                    No Attendance Adjustments
                                </strong>

                                <span>
                                    No teacher attendance records require adjustment.
                                </span>

                            </td>

                        </tr>

                    `;

                    return;

                }



                /* ==========================
                   DISPLAY RECORDS
                ========================== */

                tbody.innerHTML =
                    adjustments.map(
                        adjustment => {

                            return `

                                <tr>

                                    <td>

                                        <strong>
                                            ${escapeHTML(
                                                adjustment.fullname
                                            )}
                                        </strong>

                                    </td>


                                    <td>

                                        ${escapeHTML(
                                            adjustment.date
                                        )}

                                    </td>


                                    <td>

                                        ${escapeHTML(
                                            adjustment.adjustment_type
                                        )}

                                    </td>


                                    <td>

                                        ${
                                            adjustment.remarks
                                                ? escapeHTML(
                                                    adjustment.remarks
                                                )
                                                : "-"
                                        }

                                    </td>


                                    <td>

                                        <button
                                            type="button"
                                            class="review-btn"
                                            onclick="openAdjustmentModal(${Number(adjustment.id)})"
                                        >

                                            Review

                                        </button>

                                        <button
                                            type="button"
                                            class="delete-adjustment-btn"
                                            onclick="openDeleteAdjustmentModal(${Number(adjustment.id)}, ${escapeHtml(JSON.stringify(adjustment.fullname))}, ${escapeHtml(JSON.stringify(adjustment.date))})"
                                        >

                                            Archive

                                        </button>

                                    </td>

                                </tr>

                            `;

                        }
                    ).join("");


            } catch (error) {

                console.error(
                    "Load Adjustments Error:",
                    error
                );


                tbody.innerHTML = `

                    <tr>

                        <td
                            colspan="5"
                            class="empty-state"
                        >

                            <strong>
                                Failed to Load Adjustments
                            </strong>

                            <span>
                                Please check the connection to the server.
                            </span>

                        </td>

                    </tr>

                `;

            }

        }

/* =========================================================
   OPEN ADD ATTENDANCE ADJUSTMENT MODAL
========================================================= */

function openAddAdjustmentModal() {

    const modal =
        document.getElementById(
            "adjustmentModal"
        );

    if (!modal) {

        console.error(
            "Add adjustment modal not found."
        );

        return;
    }

    modal.classList.add("active");
    lockBodyScroll();
}


      /* =========================================================
   REVIEW ATTENDANCE ADJUSTMENT
========================================================= */

async function openAdjustmentModal(id) {

    console.log(
        "Review adjustment ID:",
        id
    );


    const modal =
        document.getElementById(
            "reviewAdjustmentModal"
        );


    if (!modal) {

        console.error(
            "Review adjustment modal not found."
        );

        return;
    }


    try {

        const response =
            await fetch(
                appUrl('data/adjustments/one') + "?id=" +
                encodeURIComponent(id)
            );


        if (!response.ok) {

            throw new Error(
                "HTTP error: " +
                response.status
            );

        }


        const result =
            await response.json();


        console.log(
            "Review Adjustment:",
            result
        );


        if (!result.success) {

            showErrorModal(
                result.message ||
                "Unable to load attendance adjustment."
            );

            return;
        }


        const adjustment =
            result.adjustment;


        /* ==========================
           FILL REVIEW MODAL
        ========================== */

        document.getElementById(
            "reviewTeacher"
        ).value =
            adjustment.fullname +
            " - " +
            adjustment.id_number;


        document.getElementById(
            "reviewDepartment"
        ).value =
            adjustment.department || "";


        document.getElementById(
            "reviewDate"
        ).value =
            adjustment.adjustment_date || "";


        document.getElementById(
            "reviewAdjustmentType"
        ).value =
            adjustment.adjustment_type || "";


        document.getElementById(
            "reviewAMArrival"
        ).value =
            adjustment.am_arrival || "";


        document.getElementById(
            "reviewAMDeparture"
        ).value =
            adjustment.am_departure || "";


        document.getElementById(
            "reviewPMArrival"
        ).value =
            adjustment.pm_arrival || "";


        document.getElementById(
            "reviewPMDeparture"
        ).value =
            adjustment.pm_departure || "";


        document.getElementById(
            "reviewRemarks"
        ).value =
            adjustment.remarks || "";


        document.getElementById(
            "reviewStatus"
        ).value =
            adjustment.status || "";


        /* ==========================
           OPEN MODAL
        ========================== */

        modal.classList.add("active");
        lockBodyScroll();


    } catch (error) {

        console.error(
            "Review Adjustment Error:",
            error
        );


        showErrorModal(
            "Unable to load the attendance adjustment."
        );

    }

}

function closeReviewAdjustmentModal() {

    const modal =
        document.getElementById(
            "reviewAdjustmentModal"
        );


    if (!modal) {
        return;
    }


    modal.classList.remove("active");
    unlockBodyScroll();

}


function closeAdjustmentModal() {

    const modal =
        document.getElementById(
            "adjustmentModal"
        );

    if (!modal) {
        return;
    }

    modal.classList.remove("active");
    unlockBodyScroll();

}



/* =========================================================
   SAVE ATTENDANCE ADJUSTMENT
   ========================================================= */

async function saveAttendanceAdjustment() {

    const teacherId =
        document.getElementById(
            "adjustmentTeacher"
        ).value;

    const date =
        document.getElementById(
            "adjustmentDate"
        ).value;

    const adjustmentType =
        document.getElementById(
            "adjustmentType"
        ).value;

    const amArrival =
        document.getElementById(
            "adjustmentAMArrival"
        ).value;

    const amDeparture =
        document.getElementById(
            "adjustmentAMDeparture"
        ).value;

    const pmArrival =
        document.getElementById(
            "adjustmentPMArrival"
        ).value;

    const pmDeparture =
        document.getElementById(
            "adjustmentPMDeparture"
        ).value;

    const remarks =
        document.getElementById(
            "adjustmentRemarks"
        ).value.trim();


    /* ==========================
       VALIDATION
    ========================== */

    if (!teacherId) {

        showErrorModal("Please select a teacher.");

        return;
    }


    if (!date) {

        showErrorModal("Please select a date.");

        return;
    }


    if (!adjustmentType) {

        showErrorModal("Please select an attendance issue.");

        return;
    }


    if (!remarks) {

        showErrorModal("Please enter remarks.");

        return;
    }


    /* ==========================
       PREVENT EMPTY TIME RECORD
    ========================== */

    if (
        !amArrival &&
        !amDeparture &&
        !pmArrival &&
        !pmDeparture &&
        adjustmentType !== "Official Business"
    ) {

        if (
            adjustmentType !== "Forgot to Scan" &&
            adjustmentType !== "Other"
        ) {

            showErrorModal(
                "Please enter at least one attendance time."
            );

            return;
        }
    }


    /* ==========================
       FORM DATA
    ========================== */

    const formData =
        new FormData();


    formData.append(
        "teacher_id",
        teacherId
    );

    formData.append(
        "adjustment_date",
        date
    );

    formData.append(
        "adjustment_type",
        adjustmentType
    );

    formData.append(
        "am_arrival",
        amArrival
    );

    formData.append(
        "am_departure",
        amDeparture
    );

    formData.append(
        "pm_arrival",
        pmArrival
    );

    formData.append(
        "pm_departure",
        pmDeparture
    );

    formData.append(
        "remarks",
        remarks
    );


    try {

        const response =
            await fetch(
                appUrl('data/adjustments'),
                {
                    method: "POST",
                    body: formData
                }
            );


        if (!response.ok) {

            throw new Error(
                "HTTP error: " +
                response.status
            );

        }


        const result =
            await response.json();


        console.log(
            "Save Adjustment Result:",
            result
        );


        if (!result.success) {

            showErrorModal(
                "Failed to save adjustment: " +
                (result.message || "Unknown error.")
            );

            return;
        }


        /* ==========================
           SUCCESS
        ========================== */

       showToast(
    "Attendance adjustment saved successfully!"
);


        closeAdjustmentModal();


        /* Reload adjustment table */

        loadAttendanceAdjustments();


        /* Clear form */

        document.getElementById(
            "adjustmentTeacher"
        ).value = "";

        document.getElementById(
            "adjustmentDate"
        ).value = "";

        document.getElementById(
            "adjustmentType"
        ).value = "";

        document.getElementById(
            "adjustmentAMArrival"
        ).value = "";

        document.getElementById(
            "adjustmentAMDeparture"
        ).value = "";

        document.getElementById(
            "adjustmentPMArrival"
        ).value = "";

        document.getElementById(
            "adjustmentPMDeparture"
        ).value = "";

        document.getElementById(
            "adjustmentRemarks"
        ).value = "";


    } catch (error) {

        console.error(
            "Save Adjustment Error:",
            error
        );


        showErrorModal(
            "An error occurred while saving the attendance adjustment."
        );

    }

}

/* ==============================
   SHOW TOAST
================================= */

function showToast(message) {

    const toast =
        document.getElementById("toastNotification");

    const toastMessage =
        document.getElementById("toastMessage");

    if (!toast || !toastMessage) {
        return;
    }

    toastMessage.textContent = message;

    toast.classList.add("show");

    setTimeout(function () {

        toast.classList.remove("show");

    }, 3000);
}

/* ==============================
   SHOW ERROR MODAL
   (replaces raw browser alert() for
   validation/failure messages)
================================= */

function showErrorModal(message) {

    const modal =
        document.getElementById("appErrorModal");

    const messageEl =
        document.getElementById("appErrorModalMessage");

    if (!modal || !messageEl) {
        return;
    }

    messageEl.textContent =
        message || "An error occurred.";

    modal.classList.add("active");
    lockBodyScroll();
}

function closeErrorModal() {

    const modal =
        document.getElementById("appErrorModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("active");
    unlockBodyScroll();
}

let adjustmentIdToDelete = null;

function openDeleteAdjustmentModal(id, name, date) {

    adjustmentIdToDelete = id;

    document.getElementById('deleteAdjustmentName').textContent = name;
    document.getElementById('deleteAdjustmentDate').textContent = date;

    document.getElementById('deleteAdjustmentModal').classList.add('active');
    lockBodyScroll();
}

function closeDeleteAdjustmentModal() {

    adjustmentIdToDelete = null;

    document.getElementById('deleteAdjustmentModal').classList.remove('active');
    unlockBodyScroll();
}

async function confirmDeleteAdjustment() {

    if (!adjustmentIdToDelete) return;

    try {

        const formData = new FormData();
        formData.append('id', adjustmentIdToDelete);

        const response = await fetch(appUrl('data/adjustments/delete'), {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            throw new Error('HTTP error: ' + response.status);
        }

        const result = await response.json();

        if (!result.success) {
            closeDeleteAdjustmentModal();
            showErrorModal(result.message || 'Failed to archive adjustment.');
            return;
        }

        showToast(result.message || 'Attendance adjustment moved to the Recycle Bin.');

        closeDeleteAdjustmentModal();
        loadAttendanceAdjustments();

    } catch (error) {
        console.error('Delete Adjustment Error:', error);
        closeDeleteAdjustmentModal();
        showErrorModal('An error occurred while deleting the adjustment.');
    }
}

function switchAdjustmentTab(tab, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById(tab + 'Tab').classList.add('active');
    btn.classList.add('active');
}

function formatPendingTime(value) {
    return value ? String(value).substring(0, 5) : '';
}

function pendingScheduleText(item) {
    const text = `${formatPendingTime(item.scheduled_in)} - ${formatPendingTime(item.scheduled_out)}`;
    if (item.shape === 'overnight') return `${text} <small>(overnight, ends next day)</small>`;
    return text;
}

function pendingPeriodDates(group) {
    const opts = { month: 'short', day: 'numeric', year: 'numeric' };
    const from = new Date(group.period_start + 'T00:00:00').toLocaleDateString('en-US', opts);
    const to = new Date(group.period_end + 'T00:00:00').toLocaleDateString('en-US', opts);
    return `${from} – ${to}`;
}

function pendingGroupHeaderRow(title, group, isCurrent) {
    return `
        <tr class="pending-period-row ${isCurrent ? 'pending-period-current' : 'pending-period-previous'}">
            <td colspan="4">
                <strong>${title}</strong>
                <span class="pending-period-dates">${escapeHTML(pendingPeriodDates(group))}</span>
                <span class="pending-period-count">${group.count} unresolved</span>
            </td>
        </tr>
    `;
}

function pendingItemRow(item) {
    const reasonTag = item.reason
        ? `<br><span class="pending-reason-tag">${escapeHTML(item.reason)}</span>`
        : '';

    // Items with real scans (Missing OUT / Early Departure / Overtime) show what was scanned
    // and cannot be "confirmed absent" - the person was there.
    const hasScans = !!item.issue;
    const scanText = hasScans
        ? `<br><small>Scanned: IN ${item.real_in ? escapeHTML(formatPendingTime(item.real_in)) : '-'} / OUT ${item.real_out ? escapeHTML(formatPendingTime(item.real_out)) : '<strong>missing</strong>'}</small>`
        : '';

    const absentBtn = hasScans
        ? ''
        : `<button class="confirm-absent-btn" onclick="openConfirmAbsentModal(${Number(item.teacher_id)}, ${escapeHtml(JSON.stringify(item.date))}, ${escapeHtml(JSON.stringify(item.fullname))})">Confirm Absent</button>`;

    return `
        <tr>
            <td><strong>${escapeHTML(item.fullname)}</strong><br><small>${escapeHTML(item.id_number)}</small></td>
            <td>${new Date(item.date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</td>
            <td>${pendingScheduleText(item)}${reasonTag}${scanText}</td>
            <td>
                <button class="review-btn" onclick="openAdjustmentModalForPending(${item.teacher_id}, '${item.date}')">Add Adjustment</button>
                ${absentBtn}
            </td>
        </tr>
    `;
}

async function loadPendingReview() {

    const tbody = document.getElementById('pendingReviewTableBody');
    const badge = document.getElementById('pendingCountBadge');

    try {

        // No look-back window: every unresolved item is returned, grouped
        // into the current review period and previous review period(s).
        const response = await fetch(appUrl('data/pending-review'));
        const result = await response.json();

        if (!result.success) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">Unable to load pending items.</td></tr>`;
            return;
        }

        const current = result.current;
        const previous = result.previous || [];
        const total = (result.counts && result.counts.total) || 0;

        badge.textContent = total;

        // keep the sidebar badge in step with what was just loaded
        if (typeof window.setPendingReviewBadge === 'function') {
            window.setPendingReviewBadge(total);
        }

        if (total === 0) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:30px;">No pending items. All caught up!</td></tr>`;
            return;
        }

        let html = pendingGroupHeaderRow('Current Review Period', current, true);
        html += current.items.length > 0
            ? current.items.map(pendingItemRow).join('')
            : `<tr><td colspan="4" style="text-align:center; color:#a89a6e; padding:14px;">No unresolved items in the current review period.</td></tr>`;

        previous.forEach(group => {
            html += pendingGroupHeaderRow('Previous Review Period', group, false);
            html += group.items.map(pendingItemRow).join('');
        });

        tbody.innerHTML = html;

    } catch (error) {
        console.error('Failed to load pending review:', error);
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">Failed to load pending items.</td></tr>`;
    }
}

let pendingAbsentTeacherId = null;
let pendingAbsentDate = null;

function openConfirmAbsentModal(teacherId, date, name) {

    pendingAbsentTeacherId = teacherId;
    pendingAbsentDate = date;

    document.getElementById('confirmAbsentName').textContent = name;
    document.getElementById('confirmAbsentDate').textContent =
        new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

    document.getElementById('confirmAbsentModal').classList.add('active');
    lockBodyScroll();
}

function closeConfirmAbsentModal() {

    pendingAbsentTeacherId = null;
    pendingAbsentDate = null;

    document.getElementById('confirmAbsentModal').classList.remove('active');
    unlockBodyScroll();
}

async function doConfirmAbsent() {

    if (!pendingAbsentTeacherId || !pendingAbsentDate) return;

    try {

        const formData = new FormData();
        formData.append('teacher_id', pendingAbsentTeacherId);
        formData.append('date', pendingAbsentDate);

        const response = await fetch(appUrl('data/confirm-absent'), { method: 'POST', body: formData });
        const result = await response.json();

        if (!result.success) {
            closeConfirmAbsentModal();
            showErrorModal(result.message || 'Failed to confirm absence.');
            return;
        }

        showToast('Personnel marked as Absent successfully.');

        closeConfirmAbsentModal();
        loadPendingReview();

    } catch (error) {
        console.error('Confirm Absent Error:', error);
        closeConfirmAbsentModal();
        showErrorModal('An error occurred while confirming absence.');
    }
}

function openAdjustmentModalForPending(teacherId, date) {

    openAddAdjustmentModal();

    document.getElementById('adjustmentTeacher').value = teacherId;
    document.getElementById('adjustmentDate').value = date;

    loadRealScansPreview();
}

/* =========================================================
   REAL SCANS PREVIEW - what the scanner actually recorded for
   the chosen personnel/date, shown before an adjustment is made.
========================================================= */

function formatScanTime(value) {
    if (!value || !/^\d{2}:\d{2}/.test(value)) return '-';
    const h = parseInt(value.substring(0, 2), 10);
    return ((h % 12) || 12) + ':' + value.substring(3, 5) + ' ' + (h >= 12 ? 'PM' : 'AM');
}

async function loadRealScansPreview() {

    const box = document.getElementById('realScansPreview');
    const teacherId = document.getElementById('adjustmentTeacher').value;
    const date = document.getElementById('adjustmentDate').value;

    if (!box) return;

    if (!teacherId || !date) {
        box.style.display = 'none';
        return;
    }

    try {

        const response = await fetch(appUrl('data/day-real-scans') + '?teacher_id=' + encodeURIComponent(teacherId) + '&date=' + encodeURIComponent(date));
        const r = await response.json();

        if (!r.success) {
            box.style.display = 'none';
            return;
        }

        const real = r.real;
        let html = '<strong>Real scans for this day</strong> (recorded by the scanner)<br>';

        if (!real) {
            html += 'No scans were recorded.';
        } else {
            html += 'IN: <strong>' + formatScanTime(r.in) + '</strong> &nbsp; OUT: <strong>' + (r.out ? formatScanTime(r.out) : 'none') + '</strong>';
            if (r.missing_out) html += '<br><span class="pending-reason-tag">Missing OUT</span>';
            if (r.overtime) html += '<br><span class="pending-reason-tag">Overtime - saving an adjustment with the OUT time approves it</span>';
            if (r.early_departure) html += '<br><span class="pending-reason-tag">Early Departure</span>';
        }

        if (r.schedule) {
            html += '<br><small>Schedule: ' + formatScanTime(r.schedule.time_in) + ' - ' + formatScanTime(r.schedule.time_out) +
                (r.break ? ' | paid break ' + formatScanTime(r.break.start) + ' - ' + formatScanTime(r.break.end) : '') + '</small>';
        }

        html += scanPhotosHTML(r);

        html += '<br><small>Only the fields you fill in below override the real scans; empty fields keep the real scan.</small>';

        box.innerHTML = html;
        box.style.display = 'block';

    } catch (error) {
        box.style.display = 'none';
    }
}

/* Kiosk webcam photos of this person's scans that day, next to their
   registered photo, so the Super Admin can check who actually scanned. */
function scanPhotosHTML(r) {

    const photos = r.scan_photos || [];

    if (!photos.length) {
        return '<br><small>No kiosk photos for this day.</small>';
    }

    const kind = k => ({ IN: 'IN', OUT: 'OUT', BREAK_OUT: 'Lunch out', BREAK_IN: 'Lunch in' }[k] || k || '');
    const outcome = o => (o === 'accepted' ? '' : ' (' + o + ')');

    let html = '<div class="scan-photo-strip"><div class="scan-photo"><img src="' +
        escapeHtml(r.registered_photo || appUrl('img/logo.png')) + '" alt="Registered photo"><span>Registered</span></div>';

    photos.forEach(p => {
        const time = formatScanTime((p.scanned_at || '').slice(11, 19));
        html += '<div class="scan-photo">' +
            (p.url ? '<a href="' + escapeHtml(p.url) + '" target="_blank" rel="noopener"><img src="' + escapeHtml(p.url) + '" alt="Scan photo"></a>'
                   : '<div class="scan-photo-missing">No photo</div>') +
            '<span>' + escapeHtml(time + ' ' + kind(p.scan_kind) + outcome(p.outcome)) + '</span></div>';
    });

    return html + '</div>';
}

/* =========================================================
   REJECTED SCAN LOG (audit evidence - never deleted here)
========================================================= */

function rejectedReasonLabel(reason) {
    if (reason === 'DUPLICATE_SCAN') return 'Duplicate scan';
    if (reason === 'ATTENDANCE_COMPLETE') return 'Attendance already complete';
    return reason;
}

async function loadRejectedScans() {

    const tbody = document.getElementById('rejectedScansTableBody');
    const badge = document.getElementById('rejectedCountBadge');
    const filter = document.getElementById('rejectedFilter').value;

    try {

        const response = await fetch(appUrl('data/rejected-scans') + '?status=' + encodeURIComponent(filter));
        const result = await response.json();

        if (!result.success) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">' + escapeHTML(result.message || 'Unable to load the Rejected Scan Log.') + '</td></tr>';
            return;
        }

        badge.textContent = result.unreviewed;

        if (result.scans.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px;">No rejected scans.</td></tr>';
            return;
        }

        tbody.innerHTML = result.scans.map(s => {

            const attempted = new Date(s.attempted_at.replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' });
            const attDate = new Date(s.attendance_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

            const review = s.reviewed_at
                ? 'Reviewed by ' + escapeHTML(s.reviewed_by || '') + '<br><small>' + escapeHTML(s.reviewed_at) + (s.review_remarks ? ' - ' + escapeHTML(s.review_remarks) : '') + '</small>'
                : '<input type="text" id="rejRemarks' + s.id + '" maxlength="255" placeholder="Remarks (optional)" style="padding:6px 8px; border:1px solid #e6dcc4; border-radius:8px; width:150px;"> ' +
                  '<button class="review-btn" onclick="markRejectedReviewed(' + s.id + ')">Mark Reviewed</button>';

            return '<tr>' +
                '<td><strong>' + escapeHTML(s.fullname) + '</strong><br><small>' + escapeHTML(s.id_number || '') + '</small></td>' +
                '<td>' + escapeHTML(attempted) + '</td>' +
                '<td>' + escapeHTML(attDate) + '</td>' +
                '<td><span class="pending-reason-tag">' + escapeHTML(rejectedReasonLabel(s.reason)) + '</span>' + (s.detail ? '<br><small>' + escapeHTML(s.detail) + '</small>' : '') + '</td>' +
                '<td>' + (s.existing_out ? escapeHTML(formatScanTime(s.existing_out)) : '-') + '</td>' +
                '<td>' + review + '</td>' +
            '</tr>';

        }).join('');

    } catch (error) {
        console.error('Failed to load rejected scans:', error);
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Failed to load the Rejected Scan Log.</td></tr>';
    }
}

async function markRejectedReviewed(id) {

    const remarks = document.getElementById('rejRemarks' + id).value;
    const body = new URLSearchParams({ id: id, remarks: remarks });

    try {

        const response = await fetch(appUrl('data/rejected-scans/review'), { method: 'POST', body: body });
        const result = await response.json();

        if (!result.success) {
            showErrorModal(result.message || 'Unable to mark this entry as reviewed.');
            return;
        }

        showToast('Marked as reviewed.');
        loadRejectedScans();

    } catch (error) {
        showErrorModal('An error occurred while saving the review.');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    fetch(appUrl('data/rejected-scans') + '?status=unreviewed')
        .then(r => r.json())
        .then(r => { if (r.success) document.getElementById('rejectedCountBadge').textContent = r.unreviewed; })
        .catch(() => {});
});

document.addEventListener('DOMContentLoaded', loadPendingReview);

function scrollPending(direction) {
    const wrapper = document.getElementById('pendingScrollWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({ top: direction * 200, behavior: 'smooth' });
}

