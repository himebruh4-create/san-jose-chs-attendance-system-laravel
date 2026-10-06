/* ATTENDANCE MONITORING (Principal) — moved from principal/attendance-monitoring.php.
   Server data comes from window.MONITORING (set by the Blade view). */

function scrollAttendanceTable(direction) {
    const wrapper = document.getElementById('attendanceScrollWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({ top: direction * 220, behavior: 'smooth' });
}

/* =========================================================
   PRINT — clean standalone popup, same pattern as Admin's
   "Print Daily Attendance", no sidebar/filters included.
   ========================================================= */

function printAttendanceMonitoring() {

    const table = document.getElementById("attendanceMonitoringTable");

    if (!table) {
        alert("There is nothing to print yet — apply filters first.");
        return;
    }

    const tableHTML = table.outerHTML;

    const now = new Date();

    const printDate = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const printTime = now.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });

    const printedBy = window.MONITORING.printedBy;

    const dateFromLabel = window.MONITORING.dateFromLabel;
    const dateToLabel = window.MONITORING.dateToLabel;
    const selectedTeacherName = window.MONITORING.selectedTeacherName;
    const reportHeading = selectedTeacherName
        ? `Attendance Monitoring &mdash; ${escapeHtml(selectedTeacherName)}`
        : "Attendance Monitoring";

    const printHTML = `
        <html>
        <head>
            <title>Attendance Monitoring</title>
            <style>
                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                }

                body { font-family: Arial; padding: 20px; }

                .print-header {
                    text-align: center;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #14390f;
                    padding-bottom: 14px;
                }

                .print-logo {
                    width: 70px;
                    height: 70px;
                    object-fit: contain;
                    margin-bottom: 6px;
                }

                .school-name {
                    font-size: 16px;
                    font-weight: 700;
                    color: #14390f;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                }

                .school-location {
                    font-size: 11px;
                    color: #a3720f;
                    margin-bottom: 10px;
                }

                .print-header h2 {
                    margin: 8px 0 4px;
                    color: #14390f;
                    font-size: 17px;
                }

                .print-header .range-label {
                    font-size: 15px;
                    font-weight: 600;
                    color: #333;
                }

                .print-header .print-timestamp {
                    font-size: 12px;
                    color: #777;
                    margin-top: 4px;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 12px;
                }

                th, td {
                    border: 1px solid #000;
                    padding: 6px 8px;
                    text-align: center;
                }

                th {
                    background: #14390f;
                    color: white;
                }

                .status-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 10px;
                    font-size: 10.5px;
                    font-weight: 600;
                }

                .status-present { background: #e8f5e9; color: #1b5e20; }
                .status-late { background: #fff3e0; color: #e65100; }
                .status-absent { background: #ffebee; color: #b71c1c; }
                .status-onleave { background: #fdf3d9; color: #a3720f; }
                .status-adjusted { background: #e3f2fd; color: #1565c0; }
                .status-schoolevent { background: #f3e5f5; color: #6a1b9a; }
                .status-pending { background: #f0f0f0; color: #616161; }
            </style>
        </head>
        <body>

            <div class="print-header">
                <img src="${appUrl('img/logo.png')}" alt="School Logo" class="print-logo">
                <div class="school-name">San Jose Community High School</div>
                <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
                <h2>${reportHeading}</h2>
                <div class="range-label">${dateFromLabel} &ndash; ${dateToLabel}</div>
                <div class="print-timestamp">Printed on ${printDate} at ${printTime} by ${escapeHtml(printedBy)}</div>
            </div>

            ${tableHTML}

        </body>
        </html>
    `;

    printHTMLDocument(printHTML);
}

