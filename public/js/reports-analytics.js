/* REPORTS & ANALYTICS — shared by Super Admin, Admin and Principal.
   Moved from the inline script of {superadmin,admin,principal}/reports-analytics.php.
   Server data comes from window.REPORT (set by the Blade view). */

const weeklyRoster = window.REPORT.roster;
const weekLabelsForRoster = window.REPORT.weekLabels;
const weekDatesForRoster = window.REPORT.weekDates;
const weekRangeLabel = window.REPORT.weekRangeLabel;

function submitMonthPicker(value) {
    const [year, month] = value.split('-');
    const url = new URL(window.location.href);
    url.searchParams.set('year', year);
    url.searchParams.set('month', month);
    url.searchParams.set('tab', 'monthly');
    window.location.href = url.toString();
}

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

new Chart(document.getElementById('weeklyAnalyticsChart'), {
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

/* ================= MONTHLY CHART ================= */

const monthlyTotals = window.REPORT.monthlyTotals;

new Chart(document.getElementById('monthlyAnalyticsChart'), {
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

/* ================= PRINT MONTHLY SUMMARY REPORT (Principal) =================
   Prints the Monthly Attendance Summary Report for the month selected above —
   the same report the Admin / Super Admin "Generate Summary Report" prints. */
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
