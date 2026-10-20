/* REPORTS — shared by Super Admin, Admin and Principal.
   Moved from the inline script of {superadmin,admin,principal}/reports-analytics.php.
   Server data comes from window.REPORT (set by the Blade view). The roster
   and chart data are only there for All Personnel; a one-person report is
   printed from its rendered section (printReportArea). */

const weeklyRoster = window.REPORT.roster;
const weekLabelsForRoster = window.REPORT.weekLabels;
const weekDatesForRoster = window.REPORT.weekDates;
const weekRangeLabel = window.REPORT.weekRangeLabel;

function switchTab(tab, btn) {

    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    document.getElementById(tab + 'Tab').classList.add('active');
    btn.classList.add('active');

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

/* ================= PRINT WEEKLY ROSTER ================= */

function statusAbbreviation(status) {
    switch (status) {
        case 'Present': return 'P';
        case 'Late': return 'L';
        case 'Absent': return 'A';
        case 'On Leave': return 'OL';
        case 'Pending Review': return 'PR';
        case 'School Event': return 'SE';
        case 'Adjusted': return 'ADJ';
        default: return '';
    }
}

function statusColor(status) {
    switch (status) {
        case 'Present': return '#1b5e20';
        case 'Late': return '#e65100';
        case 'Absent': return '#b71c1c';
        case 'On Leave': return '#a3720f';
        case 'Pending Review': return '#616161';
        case 'School Event': return '#6a1b9a';
        case 'Adjusted': return '#1565c0';
        default: return '#999';
    }
}

function printWeeklyRoster() {

    const teacherIds = Object.keys(weeklyRoster);

    let rowsHTML = '';

    teacherIds.forEach(id => {

        const t = weeklyRoster[id];

        let cells = '';

        weekDatesForRoster.forEach(date => {

            const status = t.days[date] || '';
            const abbr = statusAbbreviation(status);
            const color = statusColor(status);

            cells += `<td class="cell-status" style="color:${color};">${abbr}</td>`;
        });

        rowsHTML += `
            <tr>
                <td class="cell-teacher">${escapeHtml(t.fullname)}<small>${escapeHtml(t.id_number)}</small></td>
                <td class="cell-dept">${escapeHtml(t.department)}</td>
                ${cells}
            </tr>
        `;
    });

    const headerCells = weekLabelsForRoster.map(l => `<th>${l}</th>`).join('');

    // Same "Printed on ... by ..." line the Principal Attendance Monitoring print uses.
    const now = new Date();
    const printDate = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    const printTime = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const printedBy = window.REPORT.printedBy;

    const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Weekly Attendance Roster</title>
            <style>

                @page {
                    size: A4 portrait;
                    margin: 14mm 12mm;
                }

                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    box-sizing: border-box;
                }

                body {
                    font-family: Arial, Helvetica, sans-serif;
                    margin: 0;
                    padding: 0;
                    color: #1a1a1a;
                }

                /* ================= HEADER ================= */

                .print-header {
                    text-align: center;
                    margin-bottom: 10px;
                }

                .school-name {
                    font-size: 13px;
                    font-weight: 700;
                    letter-spacing: 0.4px;
                    color: #14390f;
                    text-transform: uppercase;
                }

                .report-title {
                    font-size: 20px;
                    font-weight: 800;
                    color: #14390f;
                    margin: 3px 0 2px;
                    letter-spacing: 0.3px;
                }

                .week-range {
                    font-size: 12px;
                    font-weight: 600;
                    color: #444;
                }

                .print-logo {
                    width: 70px;
                    height: 70px;
                    object-fit: contain;
                    margin-bottom: 6px;
                }

                .school-location {
                    font-size: 11px;
                    color: #a3720f;
                    margin-bottom: 6px;
                }

                .print-timestamp {
                    font-size: 11px;
                    color: #777;
                    margin-top: 4px;
                }

                .header-divider {
                    height: 2px;
                    background: #14390f;
                    margin: 10px 0 14px;
                }

                /* ================= TABLE ================= */

                table {
                    width: 100%;
                    border-collapse: collapse;
                    table-layout: fixed;
                    font-size: 11.5px;
                }

                colgroup .col-teacher { width: 20%; }
                colgroup .col-dept    { width: 14%; }

                thead th {
                    background: #14390f;
                    color: #ffffff;
                    font-weight: 700;
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                    padding: 8px 6px;
                    border: 1px solid #0d2a0a;
                    text-align: center;
                }

                thead th.th-teacher,
                thead th.th-dept {
                    text-align: left;
                }

                tbody td {
                    padding: 6px 7px;
                    border: 1px solid #ccc;
                    height: 26px;
                    vertical-align: middle;
                }

                tbody tr:nth-child(even) {
                    background: #f7f7f7;
                }

                td.cell-teacher {
                    text-align: left;
                    font-weight: 600;
                }

                td.cell-teacher small {
                    display: block;
                    font-weight: 400;
                    color: #666;
                    font-size: 10px;
                }

                td.cell-dept {
                    text-align: left;
                    color: #333;
                }

                td.cell-status {
                    text-align: center;
                    font-weight: 700;
                    font-size: 12px;
                }

                /* ================= LEGEND ================= */

                .legend {
                    margin-top: 14px;
                    padding-top: 8px;
                    border-top: 1px solid #ccc;
                    font-size: 10.5px;
                    color: #333;
                    text-align: center;
                }

                .legend .legend-item {
                    margin: 0 8px;
                    white-space: nowrap;
                }

                .legend .legend-item strong {
                    margin-right: 3px;
                }

                @media print {
                    body { margin: 0; }

                    /* The table is deliberately NOT break-inside: avoid — a roster
                       taller than the space left on page 1 would otherwise be pushed
                       whole onto page 2, leaving the header alone on a near-empty
                       first page. It flows naturally instead: rows are kept whole,
                       the header row repeats on each new page, and the header block
                       stays attached to the start of the table. */
                    .print-header, .header-divider, .legend {
                        break-inside: avoid;
                    }

                    .print-header, .header-divider {
                        break-after: avoid;
                    }

                    thead { display: table-header-group; }

                    tr { break-inside: avoid; }
                }


            </style>
        </head>
        <body>


            <!-- School/report header: a normal block before the table (NOT inside <thead>),
                 so it appears only on the first printed page. -->
            <div class="print-header">
                <img src="${appUrl('img/logo.png')}" alt="School Logo" class="print-logo">
                <div class="school-name">San Jose Community High School</div>
                <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
                <div class="report-title">Weekly Attendance Roster</div>
                <div class="week-range">${weekRangeLabel}</div>
                <div class="print-timestamp">Printed on ${printDate} at ${printTime} by ${escapeHtml(printedBy)}</div>
            </div>

            <div class="header-divider"></div>

            <table>
                <colgroup>
                    <col class="col-teacher">
                    <col class="col-dept">
                    ${weekLabelsForRoster.map(() => '<col>').join('')}
                </colgroup>
                <thead>
                    <tr>
                        <th class="th-teacher">Personnel</th>
                        <th class="th-dept">Department</th>
                        ${headerCells}
                    </tr>
                </thead>
                <tbody>
                    ${rowsHTML}
                </tbody>
            </table>

            <div class="legend">
                <span class="legend-item"><strong style="color:#1b5e20;">P</strong>Present</span>
                <span class="legend-item"><strong style="color:#e65100;">L</strong>Late</span>
                <span class="legend-item"><strong style="color:#b71c1c;">A</strong>Absent</span>
                <span class="legend-item"><strong style="color:#a3720f;">OL</strong>On Leave</span>
                <span class="legend-item"><strong style="color:#616161;">PR</strong>Pending Review</span>
                <span class="legend-item"><strong style="color:#6a1b9a;">SE</strong>School Event</span>
            </div>



        </body>
        </html>
    `;

    printHTMLDocument(printHTML);
}

/* ================= WEEKLY CHART ================= */

const weeklyLabels = window.REPORT.weekLabels;
const weeklyData = window.REPORT.weeklyChart;

const weeklyCanvas = document.getElementById('weeklyAnalyticsChart');

// All Personnel only: a one-person weekly report has no chart.
if (weeklyCanvas) {
    new Chart(weeklyCanvas, {
        type: 'bar',
        data: {
            labels: weeklyLabels,
            datasets: [
                { label: 'Present', data: weeklyData['Present'], backgroundColor: '#4caf50' },
                { label: 'Late', data: weeklyData['Late'], backgroundColor: '#ff9800' },
                { label: 'Pending Review', data: weeklyData['Pending Review'], backgroundColor: '#9e9e9e' },
                { label: 'Absent', data: weeklyData['Absent'], backgroundColor: '#f44336' },
                { label: 'On Leave', data: weeklyData['On Leave'], backgroundColor: '#ffc107' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
}

/* ================= MONTHLY CHART ================= */

const monthlyTotals = window.REPORT.monthlyTotals;

const monthlyCanvas = document.getElementById('monthlyAnalyticsChart');

if (monthlyCanvas) {
    new Chart(monthlyCanvas, {
        type: 'pie',
        data: {
            labels: ['Present', 'Late', 'Pending Review', 'Absent', 'On Leave'],
            datasets: [{
                data: [
                    monthlyTotals['Present'],
                    monthlyTotals['Late'],
                    monthlyTotals['Pending Review'],
                    monthlyTotals['Absent'],
                    monthlyTotals['On Leave']
                ],
                backgroundColor: ['#4caf50', '#ff9800', '#9e9e9e', '#f44336', '#ffc107']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}

/* ================= PRINT MONTHLY SUMMARY REPORT (All Personnel) =================
   Prints the Monthly Attendance Summary Report for the month selected above —
   the same report the Attendance Report page's "Generate Summary Report" prints. */
async function printMonthlySummaryReport() {
    const btn = document.getElementById('printMonthlySummaryBtn');
    const errorEl = document.getElementById('monthlyPrintError');
    errorEl.style.display = 'none';
    btn.disabled = true;
    try {
        const response = await fetch(appUrl('reports/monthly-summary') +
            `?month=${encodeURIComponent(window.REPORT.month)}&year=${encodeURIComponent(window.REPORT.year)}`);
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        printHTMLDocument(await response.text());
    } catch (error) {
        console.error('Print Monthly Summary Report error:', error);
        errorEl.textContent = 'Unable to generate the monthly summary report. Please try again.';
        errorEl.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

/* ================= PRINT A RENDERED REPORT SECTION =================
   Daily Attendance (All Personnel or one person) and the one-person
   Weekly Roster / Monthly Summary print exactly what the page shows in
   that section, under the same letterhead as the Weekly Roster. The
   Super Admin's photo strip is part of the section only when the server
   rendered it, so Admin and Principal prints never contain photos. */

function printReportArea(areaId, title, periodLabel) {
    // Thumbnails below the fold are lazy-loaded on the page; in the print
    // frame every image must load before it prints.
    const content = document.getElementById(areaId).innerHTML.replace(/\sloading="lazy"/g, '');

    const now = new Date();
    const printDate = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    const printTime = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(title)}</title>
            <style>

                @page {
                    size: A4 portrait;
                    margin: 14mm 12mm;
                }

                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    box-sizing: border-box;
                }

                body {
                    font-family: Arial, Helvetica, sans-serif;
                    margin: 0;
                    padding: 0;
                    color: #1a1a1a;
                }

                /* ================= HEADER (same as the Weekly Roster) ================= */

                .print-header { text-align: center; margin-bottom: 10px; }
                .print-logo { width: 70px; height: 70px; object-fit: contain; margin-bottom: 6px; }
                .school-name { font-size: 13px; font-weight: 700; letter-spacing: 0.4px; color: #14390f; text-transform: uppercase; }
                .school-location { font-size: 11px; color: #a3720f; margin-bottom: 6px; }
                .report-title { font-size: 20px; font-weight: 800; color: #14390f; margin: 3px 0 2px; letter-spacing: 0.3px; }
                .week-range { font-size: 12px; font-weight: 600; color: #444; }
                .print-timestamp { font-size: 11px; color: #777; margin-top: 4px; }
                .header-divider { height: 2px; background: #14390f; margin: 10px 0 14px; }

                /* ================= ONE PERSON ================= */

                .personnel-header {
                    display: flex;
                    align-items: center;
                    gap: 14px;
                    border: 1px solid #ccc;
                    border-radius: 6px;
                    padding: 10px 12px;
                    margin-bottom: 12px;
                }

                .personnel-photo { width: 70px; height: 70px; object-fit: cover; border-radius: 6px; border: 1px solid #ccc; }
                .personnel-name { font-size: 15px; font-weight: 800; color: #14390f; margin-bottom: 4px; }
                .personnel-meta { display: flex; flex-wrap: wrap; gap: 4px 22px; margin: 0; }
                .personnel-meta dt { font-size: 9.5px; font-weight: 700; color: #666; text-transform: uppercase; }
                .personnel-meta dd { margin: 0; font-size: 11.5px; font-weight: 600; }

                .summary-stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; margin-bottom: 12px; }
                .summary-stat { border: 1px solid #ccc; border-radius: 6px; padding: 6px 4px; text-align: center; }
                .summary-stat span { display: block; font-size: 9px; font-weight: 700; color: #666; text-transform: uppercase; }
                .summary-stat strong { font-size: 13px; color: #14390f; }

                /* ================= TABLE (same rules as the Weekly Roster) ================= */

                table { width: 100%; border-collapse: collapse; font-size: 11.5px; }

                thead th {
                    background: #14390f;
                    color: #ffffff;
                    font-weight: 700;
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                    padding: 8px 6px;
                    border: 1px solid #0d2a0a;
                    text-align: center;
                }

                tbody td {
                    padding: 6px 7px;
                    border: 1px solid #ccc;
                    height: 26px;
                    vertical-align: middle;
                    text-align: center;
                }

                .th-left, .td-left { text-align: left; }
                .report-record:nth-of-type(even) > tr:first-child { background: #f7f7f7; }
                .empty-note { color: #777; padding: 14px; }

                .status-tag { font-weight: 700; }
                .status-present { color: #1b5e20; }
                .status-late { color: #e65100; }
                .status-absent { color: #b71c1c; }
                .status-leave { color: #a3720f; }
                .status-pending { color: #616161; }
                .status-event { color: #6a1b9a; }
                .status-adjusted { color: #1565c0; }
                .status-none { color: #999; }
                .status-detail { display: block; font-size: 9.5px; color: #666; }

                /* ================= PHOTO STRIP (Super Admin) ================= */

                /* One cell under each scan column, centered like the times */
                .photo-strip-row td { padding: 5px 4px 7px; border-top: 0; background: #ffffff; vertical-align: top; }
                .strip-item { margin: 0; display: flex; flex-direction: column; align-items: center; gap: 3px; }

                .strip-thumb {
                    flex: 0 0 auto;
                    width: 60px;
                    height: 45px;
                    padding: 0;
                    border: 1px solid #bbb;
                    border-radius: 4px;
                    background: none;
                    overflow: hidden;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .strip-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
                .strip-missing { background: #e4e2dc; color: #77736a; font-size: 8px; font-weight: 700; }
                .strip-empty { background: #f4f4f4; color: #aaa; font-size: 10px; }
                .strip-item figcaption { font-size: 9px; line-height: 1.3; text-align: center; }
                .strip-item figcaption strong { display: block; }

                @media print {
                    body { margin: 0; }

                    /* Same multi-page behaviour as the roster: the header prints
                       once, the table flows across pages, the column-header row
                       repeats, and a record never splits from its photo strip. */
                    .print-header, .header-divider, .personnel-header, .summary-stats {
                        break-inside: avoid;
                    }

                    .print-header, .header-divider {
                        break-after: avoid;
                    }

                    thead { display: table-header-group; }

                    .report-record, tr { break-inside: avoid; }
                }

            </style>
        </head>
        <body>

            <div class="print-header">
                <img src="${appUrl('img/logo.png')}" alt="School Logo" class="print-logo">
                <div class="school-name">San Jose Community High School</div>
                <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
                <div class="report-title">${escapeHtml(title)}</div>
                <div class="week-range">${escapeHtml(periodLabel)}</div>
                <div class="print-timestamp">Printed on ${printDate} at ${printTime} by ${escapeHtml(window.REPORT.printedBy)}</div>
            </div>

            <div class="header-divider"></div>

            ${content}

        </body>
        </html>
    `;

    printHTMLDocument(printHTML);
}

/* ================= SCAN PHOTO VIEWER (Super Admin) ================= */

function openScanPhoto(button) {
    const image = document.getElementById('scanPhotoModalImage');

    document.getElementById('scanPhotoModalTitle').textContent = button.dataset.caption;
    image.src = button.dataset.src;
    image.alt = button.dataset.caption;

    document.getElementById('scanPhotoModal').classList.add('active');
    lockBodyScroll();
}

function closeScanPhoto() {
    const modal = document.getElementById('scanPhotoModal');

    if (!modal || !modal.classList.contains('active')) {
        return;
    }

    modal.classList.remove('active');
    document.getElementById('scanPhotoModalImage').removeAttribute('src');
    unlockBodyScroll();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeScanPhoto();
    }
});
