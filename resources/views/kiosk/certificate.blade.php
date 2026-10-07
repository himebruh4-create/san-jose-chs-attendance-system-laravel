<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kiosk Certificate</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/login.css') }}">
<style>
    body { padding: 24px 16px; }
    .login-card.cert-card { max-width: 560px; text-align: left; }
    .cert-card h2, .cert-card .login-icon, .cert-card p.subtitle { text-align: center; }
    .cert-download { display: block; text-decoration: none; text-align: center; margin-bottom: 22px; }
    .cert-card details { border: 1px solid #eadfc4; border-radius: 10px; margin-bottom: 10px; background: #fffaf0; }
    .cert-card summary { cursor: pointer; padding: 11px 14px; font-weight: 600; color: #14390f; font-size: 14px; }
    .cert-card summary i { width: 20px; color: #e8a317; }
    .cert-card ol { margin: 0; padding: 0 18px 12px 34px; font-size: 13px; color: #4a4330; line-height: 1.6; }
    .cert-card .note { font-size: 12.5px; color: #8a7d5c; line-height: 1.55; margin: 14px 0 0; }
    .cert-card .test-link { word-break: break-all; color: #14390f; font-weight: 600; }
    .cert-card dl { font-size: 12px; color: #4a4330; margin: 16px 0 0; padding: 12px 14px; background: #f7f1e1; border-radius: 10px; }
    .cert-card dt { font-weight: 600; color: #14390f; margin-top: 6px; }
    .cert-card dt:first-child { margin-top: 0; }
    .cert-card dd { margin: 2px 0 0; font-family: Consolas, 'Courier New', monospace; word-break: break-all; }
    .cert-card .back-link { display: block; text-align: center; margin-top: 18px; color: #8a7d5c; font-size: 12.5px; text-decoration: none; }
</style>
</head>
<body>

<div class="login-card cert-card">
    <div class="login-icon"><i class="fa-solid fa-certificate"></i></div>
    <h2>Kiosk Certificate</h2>
    <p class="subtitle">
        Install this once on each kiosk device. It lets the device trust this system's secure (HTTPS) address,
        which the webcam needs.
    </p>

    @if (! $certificate)
        <div class="error-box">
            The certificate has not been created on the server yet. On the server PC, run
            <strong>deploy\make-ssl-cert.ps1</strong> (see the README), then reload this page.
        </div>
    @else
        <a href="{{ route('certificate.download') }}" class="login-btn cert-download">
            <i class="fa-solid fa-download"></i> Download certificate
        </a>

        <details data-os="windows">
            <summary><i class="fa-brands fa-windows"></i> Windows (Chrome / Edge)</summary>
            <ol>
                <li>Open the downloaded <strong>sjchs-ca.crt</strong> and click <strong>Install Certificate…</strong></li>
                <li>Choose <strong>Local Machine</strong>, click Next, and allow the admin prompt.</li>
                <li>Choose <strong>Place all certificates in the following store</strong> &rarr; Browse &rarr;
                    <strong>Trusted Root Certification Authorities</strong>.</li>
                <li>Click Next, then Finish.</li>
                <li>Close the browser completely and open it again.</li>
            </ol>
        </details>

        <details data-os="android">
            <summary><i class="fa-brands fa-android"></i> Android (Chrome)</summary>
            <ol>
                <li>Open <strong>Settings</strong> and search for <strong>CA certificate</strong>
                    (usually Security &rarr; More security settings &rarr; Install from device storage).</li>
                <li>Tap <strong>CA certificate</strong> &rarr; <strong>Install anyway</strong>.</li>
                <li>Pick <strong>sjchs-ca.crt</strong> from Downloads. Android asks for a screen lock if there is none.</li>
            </ol>
        </details>

        <details data-os="ios">
            <summary><i class="fa-brands fa-apple"></i> iPhone / iPad (Safari)</summary>
            <ol>
                <li>Open this page in <strong>Safari</strong>, tap Download certificate, then <strong>Allow</strong>.</li>
                <li>Settings &rarr; General &rarr; <strong>VPN &amp; Device Management</strong> &rarr; tap the
                    profile &rarr; <strong>Install</strong>.</li>
                <li>Settings &rarr; General &rarr; About &rarr; <strong>Certificate Trust Settings</strong> &rarr;
                    switch on <strong>{{ $certificate['name'] }}</strong>. Without this step HTTPS will not work.</li>
            </ol>
        </details>

        <details data-os="firefox">
            <summary><i class="fa-brands fa-firefox-browser"></i> Firefox (any computer)</summary>
            <ol>
                <li>Firefox keeps its own list. Settings &rarr; Privacy &amp; Security &rarr; Certificates &rarr;
                    <strong>View Certificates</strong> &rarr; Authorities &rarr; <strong>Import</strong>.</li>
                <li>Pick <strong>sjchs-ca.crt</strong> and tick <strong>Trust this CA to identify websites</strong>.</li>
            </ol>
        </details>

        <p class="note">
            <strong>Check it worked:</strong> open
            <a class="test-link" href="{{ $httpsKioskUrl }}">{{ $httpsKioskUrl }}</a>.
            It should open with no warning and a padlock. It stays installed until someone removes it or the device is reset.
        </p>

        <dl>
            <dt>Certificate</dt>
            <dd>{{ $certificate['name'] }}</dd>
            <dt>Valid until</dt>
            <dd>{{ $certificate['expires']->format('F j, Y') }}</dd>
            <dt>SHA-256 fingerprint</dt>
            <dd>{{ $certificate['sha256'] }}</dd>
            <dt>SHA-1 fingerprint (Windows "Thumbprint")</dt>
            <dd>{{ $certificate['sha1'] }}</dd>
        </dl>
        <p class="note">
            To be sure the file was not swapped on the way, the fingerprint the device shows when installing should
            match the one on this page when it is opened on the server PC itself.
        </p>
    @endif

    <a href="{{ route('login') }}" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to login</a>
</div>

<script>
(function () {
    const ua = navigator.userAgent;
    const os = /firefox/i.test(ua) ? 'firefox'
        : /android/i.test(ua) ? 'android'
        : /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1) ? 'ios'
        : /windows/i.test(ua) ? 'windows' : null;
    const match = os && document.querySelector('details[data-os="' + os + '"]');
    if (match) { match.open = true; }
})();
</script>

</body>
</html>
