<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Lets kiosk devices download the local CA certificate made by
 * deploy/make-ssl-cert.ps1, so they trust the HTTPS site the webcam needs.
 * Public on purpose: a CA certificate is not a secret, and a device needs it
 * before anyone has signed in on it.
 */
class CertificateController extends Controller
{
    public function show(Request $request): View
    {
        $pem = $this->caCertificate();
        $certificate = null;

        if ($pem !== null) {
            $info = openssl_x509_parse($pem);
            $certificate = [
                'name' => $info['subject']['CN'] ?? 'Local CA',
                'expires' => Carbon::createFromTimestamp($info['validTo_time_t']),
                'sha256' => $this->fingerprint($pem, 'sha256'),
                'sha1' => $this->fingerprint($pem, 'sha1'),
            ];
        }

        return view('kiosk.certificate', [
            'certificate' => $certificate,
            'httpsKioskUrl' => 'https://'.$request->getHost().':'.config('app.https_port').'/kiosk',
        ]);
    }

    public function download(): Response
    {
        $pem = $this->caCertificate();

        abort_if($pem === null, 404);

        return response($pem, 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="sjchs-ca.crt"',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The configured CA certificate as PEM, or null when the file is missing or
     * is anything other than exactly one CA certificate. A file holding a
     * private key is never read out, even when the path points at it by mistake.
     */
    private function caCertificate(): ?string
    {
        $path = (string) config('app.ca_certificate_path');

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, 'PRIVATE KEY') || substr_count($contents, '-----BEGIN CERTIFICATE-----') !== 1) {
            return null;
        }

        try {
            $certificate = openssl_x509_read($contents);
        } catch (\ErrorException) {
            return null;
        }

        if ($certificate === false) {
            return null;
        }

        $basicConstraints = openssl_x509_parse($certificate)['extensions']['basicConstraints'] ?? '';

        if (! str_contains($basicConstraints, 'CA:TRUE')) {
            return null;
        }

        // Re-export so only the certificate itself is ever sent.
        openssl_x509_export($certificate, $pem);

        return $pem;
    }

    /** Fingerprint as devices show it, e.g. "AB:12:...". */
    private function fingerprint(string $pem, string $algorithm): string
    {
        return implode(':', str_split(strtoupper(openssl_x509_fingerprint($pem, $algorithm)), 2));
    }
}
