/* Print Daily Attendance (Admin dashboard). Moved from admin/admin-dashboard.php;
   the page defines printedBy. */

function printAttendance() {
    const content = document.getElementById("printArea").innerHTML;

    const now = new Date();

    const logDate = now.toLocaleDateString('en-US', {
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


    const printHTML = `
        <html>
        <head>
            <title>Print Attendance</title>
            <style>
                @page { size: A4 portrait; margin: 15mm; }

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

                .print-header .log-date {
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
                }

                th, td {
                    border: 1px solid #000;
                    padding: 8px;
                    text-align: center;
                }

                th {
                    background: #14390f;
                    color: white;
                }

            </style>
        </head>
        <body>


            <div class="print-header">
                <img src="${appUrl('img/logo.png')}" alt="School Logo" class="print-logo">
                <div class="school-name">San Jose Community High School</div>
                <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
                <h2>Live Personnel Attendance Log</h2>
                <div class="log-date">${logDate}</div>
                <div class="print-timestamp">Printed on ${logDate} at ${printTime} by ${escapeHtml(printedBy)}</div>
            </div>

            ${content}


        </body>
        </html>
    `;

    printHTMLDocument(printHTML);
}
