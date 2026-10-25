/* REPORTS — shared by Super Admin, Admin and Principal.
   Moved from the inline script of {superadmin,admin,principal}/reports-analytics.php.
   Server data comes from window.REPORT (set by the Blade view). The roster
   and chart data are only there for All Personnel. The Daily report, one
   person's Weekly / Monthly report, the Monthly Summary and Print Absences
   are printouts built by the server (no scan photos); only the All
   Personnel Weekly Roster is built here. */

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
    const printTime = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    const printedBy = window.REPORT.printedBy;
    const printJob = window.REPORT.weeklyRosterPrint;

    const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(printJob.title)}</title>
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

    printHTMLDocument(printHTML, printJob);
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

/* ================= MONTHLY CALENDAR =================
   A day's names grouped by status with their time in (All Personnel), or
   one person's status and scan times. The data is window.REPORT.calendar,
   built with the monthly lists; it never contains scan photos. */

const CALENDAR_STATUSES = { 'Present': 'present', 'Late': 'late', 'Absent': 'absent', 'On Leave': 'leave', 'Pending Review': 'pending' };

function calendarGroupsHTML(day) {
    const sections = Object.entries(CALENDAR_STATUSES)
        .filter(([status]) => (day.groups[status] || []).length > 0)
        .map(([status, cls]) => `
            <section class="status-box ${cls} cal-group">
                <h4>${escapeHtml(status)} (${day.groups[status].length})</h4>
                <ul>
                    ${day.groups[status].map(item => `
                        <li>
                            <span>${escapeHtml(item.name)}</span>
                            <span class="cal-time">${escapeHtml(item.time || item.detail)}</span>
                        </li>`).join('')}
                </ul>
            </section>`);

    return sections.length
        ? `<div class="cal-groups">${sections.join('')}</div>`
        : '<p class="empty-note">No attendance to report for this day.</p>';
}

function calendarPersonHTML(day) {
    if (!day.status) {
        return '<p class="empty-note">No attendance to report for this day.</p>';
    }

    const cls = CALENDAR_STATUSES[day.status] || 'none';
    const detail = day.detail && day.detail !== day.status ? `<small class="status-detail">${escapeHtml(day.detail)}</small>` : '';
    const scan = (label, column) => `<div><dt>${label}</dt><dd>${escapeHtml(day.scans[column] || '—')}</dd></div>`;

    return `
        <p class="cal-person-status"><span class="status-tag status-${cls}">${escapeHtml(day.status)}</span>${detail}</p>
        <dl class="cal-scans">
            ${scan('AM Arrival', 'am_arrival')}
            ${scan('AM Departure', 'am_departure')}
            ${scan('PM Arrival', 'pm_arrival')}
            ${scan('PM Departure', 'pm_departure')}
        </dl>`;
}

function openCalendarDay(date) {
    const day = window.REPORT.calendar.days[date];

    if (!day) {
        return;
    }

    const title = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    const label = day.label ? `<p class="cal-day-label">${escapeHtml(day.labelTitle || day.label)}</p>` : '';

    document.getElementById('calendarDayTitle').textContent = title;
    document.getElementById('calendarDayBody').innerHTML = label +
        (window.REPORT.calendar.person ? calendarPersonHTML(day) : calendarGroupsHTML(day));

    // The Daily tab for this date, keeping the Personnel filter and the other tabs' periods.
    const dailyUrl = new URL(window.location.href);
    dailyUrl.searchParams.set('tab', 'daily');
    dailyUrl.searchParams.set('date', date);
    document.getElementById('calendarDayDailyLink').href = dailyUrl.toString();

    document.getElementById('calendarDayModal').classList.add('active');
    lockBodyScroll();
    document.querySelector('#calendarDayModal .modal-close').focus();
}

function closeCalendarDay() {
    const modal = document.getElementById('calendarDayModal');

    if (!modal || !modal.classList.contains('active')) {
        return;
    }

    modal.classList.remove('active');
    unlockBodyScroll();
}

/* "This Month's Status": a list of more than 5 names shows the first 5. */
function toggleStatusList(button) {
    const list = document.getElementById(button.getAttribute('aria-controls'));
    const expanded = list.classList.toggle('is-collapsed') === false;

    button.setAttribute('aria-expanded', String(expanded));
    button.textContent = expanded ? 'Show fewer' : button.dataset.more;
}

/* ================= PRINTOUTS BUILT BY THE SERVER =================
   Print Daily Attendance, one person's Print Weekly Roster / Monthly
   Summary Report, and Print Absences: the server builds the printout for
   the tab's range and Personnel filter (the URL is on the button) and logs
   it; this fetches and prints it. */
async function printServerReport(button) {
    button.disabled = true;
    try {
        const response = await fetch(button.dataset.url, { headers: { 'Accept': 'text/html' } });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        printHTMLDocument(await response.text());
    } catch (error) {
        console.error('Print error:', error);
        showMessageModal(button.dataset.error || 'Unable to generate the report. Please try again.', { title: 'Something Went Wrong', type: 'error' });
    } finally {
        button.disabled = false;
    }
}

/* ================= PRINT MONTHLY SUMMARY REPORT (All Personnel) =================
   Prints the Monthly Attendance Summary Report for the month selected above. */
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
        closeCalendarDay();
    }
});
