<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recovery Codes</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<style>
    /* Shown once, right after the codes are generated (Settings -> Account
       Management). Printable on its own; never cached by the browser. */
    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 32px 16px;
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #f5f0e1;
        color: #2b2b1f;
    }

    .sheet {
        max-width: 640px;
        margin: 0 auto;
        padding: 28px 30px;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 30px rgba(20, 57, 15, 0.12);
    }

    .letterhead { text-align: center; border-bottom: 2px solid #14390f; padding-bottom: 12px; margin-bottom: 18px; }
    .letterhead img { width: 60px; height: 60px; object-fit: contain; }
    .school-name { font-size: 13px; font-weight: 700; color: #14390f; text-transform: uppercase; letter-spacing: 0.4px; }
    h1 { margin: 6px 0 2px; font-size: 22px; color: #14390f; }
    .meta { font-size: 12.5px; color: #6b6248; }

    .once {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        margin: 0 0 18px;
        padding: 12px 14px;
        border-radius: 10px;
        border: 1px solid #f0c36d;
        background: #fff6e0;
        color: #7a4b00;
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.45;
    }

    .codes {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin: 0;
        padding: 0;
        list-style: none;
        counter-reset: code;
    }

    .codes li {
        counter-increment: code;
        padding: 12px 14px;
        border: 1.5px dashed #c9bb93;
        border-radius: 10px;
        font-family: Consolas, 'Courier New', monospace;
        font-size: 19px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-align: center;
        color: #14390f;
    }

    .codes li::before {
        content: counter(code) ".";
        margin-right: 8px;
        font-size: 12px;
        color: #a89a6e;
        letter-spacing: 0;
    }

    .how { margin: 18px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: #4a4330; }

    .paper-only { margin: 14px 0 0; font-size: 13px; font-weight: 700; color: #7a4b00; }

    .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }

    .actions a, .actions button {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 20px;
        border: none;
        border-radius: 9px;
        font: inherit;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }

    .print-btn { background: #e8a317; color: #3a2600; }
    .back-btn { background: #14390f; color: #ffffff; }

    @media (max-width: 479.98px) {
        .sheet { padding: 22px 16px; }
        .codes { grid-template-columns: minmax(0, 1fr); }
    }

    @media print {
        @page { size: A4 portrait; margin: 16mm; }
        body { padding: 0; background: #ffffff; }
        .sheet { box-shadow: none; padding: 0; max-width: none; }
        .actions { display: none; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="sheet">

    <div class="letterhead">
        <img src="{{ asset('img/logo.png') }}" alt="School Logo">
        <div class="school-name">San Jose Community High School</div>
        <h1>Super Admin Recovery Codes</h1>
        <div class="meta">{{ $account->email }} &middot; generated {{ $generatedAt->format('F j, Y g:i A') }}</div>
    </div>

    <div class="once" role="alert">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span>These codes are shown only now. Print them or write them down and keep them somewhere safe. Any older codes no longer work.</span>
    </div>

    <ol class="codes">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ol>

    <ul class="how">
        <li>If you forget your password and your security answers: Login &rarr; Forgot Password &rarr; <em>use a recovery code</em>.</li>
        <li>Each code works once. Generate a new set in Settings &rarr; Account Management when few are left.</li>
    </ul>

    <p class="paper-only"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Print on paper and keep in a sealed envelope. Don't save as PDF.</p>

    <div class="actions">
        <button type="button" class="print-btn" onclick="printRecoveryCodes()"><i class="fa-solid fa-print"></i> Print Codes</button>
        <a href="{{ route('superadmin.settings', ['tab' => 'accounts']) }}" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Settings</a>
    </div>

</div>

<script>
/* While printing, the page's title is the printout's name ("Recovery Codes -
   2026-10-25 - Administrative Aide III"), which browsers suggest as a file
   name; the print is recorded in the Audit Log (never the codes). */
(function () {
    var pageTitle = document.title;
    var printJob = @json($print);

    window.addEventListener('beforeprint', function () {
        document.title = printJob.title;
        fetch(@json(route('reports.print-log')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
            credentials: 'same-origin',
            body: JSON.stringify({ report: printJob.report, range: printJob.range }),
            keepalive: true
        }).catch(function () {});
    });

    window.addEventListener('afterprint', function () {
        document.title = pageTitle;
    });

    window.printRecoveryCodes = function () {
        document.title = printJob.title;
        window.print();
    };
})();
</script>

</body>
</html>
