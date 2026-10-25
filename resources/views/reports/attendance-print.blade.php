<!DOCTYPE html>
<html>
<head>
    <title>{{ $printTitle }}</title>
    <style>
        /* Same print design as the Weekly Roster: page setup, letterhead,
           colors and table rules. No scan photos, for any role. */

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

        /* e.g. "2 personnel not yet recorded today." (icon font not loaded here) */
        .help-note { margin: 0 0 8px; font-size: 11px; color: #555; }
        .help-note i { display: none; }
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

        @media print {
            body { margin: 0; }

            /* Same multi-page behaviour as the roster: the header prints
               once, the table flows across pages and the column-header row
               repeats. */
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
    <img src="{{ asset('img/logo.png') }}" alt="School Logo" class="print-logo">
    <div class="school-name">San Jose Community High School</div>
    <div class="school-location">Gen. Mariano Alvarez, Cavite</div>
    <div class="report-title">{{ $reportTitle }}</div>
    <div class="week-range">{{ $periodLabel }}</div>
    <div class="print-timestamp">Printed on {{ $printDate }} at {{ $printTime }} by {{ $printedBy }}</div>
</div>

<div class="header-divider"></div>

@include('reports.partials.report-section', ['showPhotos' => false])

</body>
</html>
