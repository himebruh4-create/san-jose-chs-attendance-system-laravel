<!DOCTYPE html>
<html>
<head>
    <title>Monthly Summary Report</title>
    <style>
        /* Same print design as the Weekly Attendance Roster (Reports -> Print Weekly Roster):
           identical page setup, header, colors and table rules; only the report title,
           the period line and the 11 summary columns differ. */

        /* Same margins as the roster. This table has 11 columns (the roster has 8), so it
           uses A4 landscape — at the roster's font sizes and cell padding the column
           headings and IDs do not fit an A4 portrait width. */
        @page {
            size: A4 landscape;
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

        /* column widths for the 11 summary columns (add up to 100%) */
        colgroup .col-id    { width: 9%; }
        colgroup .col-name  { width: 17%; }
        colgroup .col-dept  { width: 15%; }
        colgroup .col-stat  { width: 9%; }
        colgroup .col-emp   { width: 9.5%; }
        colgroup .col-num   { width: 6%; }
        colgroup .col-hours { width: 10%; }

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
            text-align: center;
            overflow-wrap: break-word;
        }

        tbody tr:nth-child(even) {
            background: #f7f7f7;
        }

        td.cell-teacher {
            text-align: left;
            font-weight: 600;
        }

        td.cell-dept {
            text-align: left;
            color: #333;
        }

        @media print {
            body { margin: 0; }

            /* Same multi-page behaviour as the roster: the school header prints once
               (it is a plain block before the table, never inside <thead>); the table
               flows across pages, rows are kept whole, and only the column-header row
               repeats on each new page. */
            .print-header, .header-divider {
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
    <img src="{{ asset('img/logo.png') }}" alt="School Logo" class="print-logo">
    <div class="school-name">San Jose Community High School</div>
    <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
    <div class="report-title">Monthly Attendance Summary Report</div>
    <div class="week-range">{{ $monthLabel }}</div>
    <div class="week-range">Covered: {{ $startDate }} &rarr; {{ $endDate }}</div>
    <div class="print-timestamp">Printed on {{ $printDate }} at {{ $printTime }} by {{ $printedBy }}</div>
</div>

<div class="header-divider"></div>

<table>
    <colgroup>
        <col class="col-id">
        <col class="col-name">
        <col class="col-dept">
        <col class="col-stat">
        <col class="col-emp">
        <col class="col-num">
        <col class="col-num">
        <col class="col-num">
        <col class="col-num">
        <col class="col-num">
        <col class="col-hours">
    </colgroup>
    <thead>
        <tr>
            <th>ID</th>
            <th class="th-teacher">Name</th>
            <th class="th-dept">Department</th>
            <th>Status</th>
            <th>Employment</th>
            <th>On Time</th>
            <th>Late</th>
            <th>Absent</th>
            <th>On Leave</th>
            <th>Total</th>
            <th>Total Hours</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($summaryReports as $t)
        <tr>
            <td>{{ $t['id_number'] }}</td>
            <td class="cell-teacher">{{ $t['fullname'] }}</td>
            <td class="cell-dept">{{ $t['department'] }}</td>
            <td>{{ $t['academic_status'] }}</td>
            <td>{{ $t['employment_type'] }}</td>
            <td>{{ (int) $t['on_time'] }}</td>
            <td>{{ (int) $t['late_count'] }}</td>
            <td>{{ (int) $t['absent_count'] }}</td>
            <td>{{ (int) $t['on_leave_count'] }}</td>
            <td>{{ (int) $t['total'] }}</td>
            <td><strong>{{ $t['total_hours_pretty'] }}</strong></td>
        </tr>
    @endforeach
    </tbody>
</table>

</body>
</html>
