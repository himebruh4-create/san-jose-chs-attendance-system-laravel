<!DOCTYPE html>
<html>
<head>
    <title>{{ $printTitle }}</title>
    <style>
        /* Same print design as the Weekly Roster and the Monthly Summary
           Report: page setup, letterhead, colors and table rules. */

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

        .print-header { text-align: center; margin-bottom: 10px; }
        .print-logo { width: 70px; height: 70px; object-fit: contain; margin-bottom: 6px; }
        .school-name { font-size: 13px; font-weight: 700; letter-spacing: 0.4px; color: #14390f; text-transform: uppercase; }
        .school-location { font-size: 11px; color: #a3720f; margin-bottom: 6px; }
        .report-title { font-size: 20px; font-weight: 800; color: #14390f; margin: 3px 0 2px; letter-spacing: 0.3px; }
        .print-timestamp { font-size: 11px; color: #777; margin-top: 4px; }
        .header-divider { height: 2px; background: #14390f; margin: 10px 0 14px; }

        /* ================= ONE PERSON ================= */

        .personnel-box {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 26px;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 12px;
        }

        .personnel-box span { display: block; font-size: 9.5px; font-weight: 700; color: #666; text-transform: uppercase; }
        .personnel-box strong { color: #14390f; }

        /* ================= TABLE ================= */

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
            overflow-wrap: break-word;
        }

        tbody tr:nth-child(even) { background: #f7f7f7; }

        .th-left, .td-left { text-align: left; }
        td.td-name { text-align: left; font-weight: 600; }

        .total-line { margin-top: 10px; font-size: 12px; text-align: right; }
        .total-line strong { color: #b71c1c; }

        .empty-state {
            margin-top: 30px;
            padding: 22px;
            border: 1px dashed #bbb;
            border-radius: 6px;
            text-align: center;
            font-size: 13px;
            color: #555;
        }

        @media print {
            body { margin: 0; }

            .print-header, .header-divider, .personnel-box { break-inside: avoid; }
            .print-header, .header-divider { break-after: avoid; }

            thead { display: table-header-group; }
            tr { break-inside: avoid; }
        }
    </style>
</head>

<body>

<div class="print-header">
    <img src="{{ asset('img/logo.png') }}" alt="School Logo" class="print-logo">
    <div class="school-name">San Jose Community High School</div>
    <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
    <div class="report-title">Absence Report &ndash; {{ $periodLabel }}</div>
    <div class="print-timestamp">Printed on {{ $printDate }} at {{ $printTime }} by {{ $printedBy }}</div>
</div>

<div class="header-divider"></div>

@if ($person)
    <div class="personnel-box">
        <div><span>ID Number</span><strong>{{ $person->id_number }}</strong></div>
        <div><span>Name</span><strong>{{ $person->fullname }}</strong></div>
        <div><span>Position</span><strong>{{ $person->department }}</strong></div>
        <div><span>Personnel Type</span><strong>{{ $typeLabels[$person->academic_status] ?? $person->academic_status }}</strong></div>
    </div>
@endif

@if (empty($absences))

    <div class="empty-state">No absences recorded for this period.</div>

@elseif ($person)

    @php $dates = $absences[0]['dates']; @endphp
    <table>
        <thead>
            <tr>
                <th style="width: 12%;">No.</th>
                <th class="th-left">Date Absent</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dates as $date)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td class="td-left">{{ date('l, F j, Y', strtotime($date)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="total-line">Total days absent: <strong>{{ count($dates) }}</strong></div>

@else

    <table>
        <thead>
            <tr>
                <th style="width: 6%;">No.</th>
                <th style="width: 12%;">ID Number</th>
                <th class="th-left" style="width: 19%;">Name</th>
                <th class="th-left" style="width: 14%;">Position</th>
                <th style="width: 15%;">Personnel Type</th>
                <th class="th-left">Date(s) Absent</th>
                <th style="width: 9%;">Total Days Absent</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($absences as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $row['id_number'] }}</td>
                    <td class="td-name">{{ $row['fullname'] }}</td>
                    <td class="td-left">{{ $row['department'] }}</td>
                    <td>{{ $typeLabels[$row['academic_status']] ?? $row['academic_status'] }}</td>
                    <td class="td-left">{{ implode(', ', array_map(fn ($d) => date('M j', strtotime($d)), $row['dates'])) }}</td>
                    <td><strong>{{ count($row['dates']) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

@endif

</body>
</html>
