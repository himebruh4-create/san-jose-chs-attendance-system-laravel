<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /certificate hands kiosk devices the local CA certificate, and nothing else
 * that may sit next to it (the CA private key above all).
 */
class CertificatePageTest extends TestCase
{
    private const FAKE_PRIVATE_KEY = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQ\n-----END PRIVATE KEY-----\n";

    private function useCertificateFile(string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sjchs-ca-');
        File::put($path, $contents);
        $this->beforeApplicationDestroyed(fn () => File::delete($path));

        config(['app.ca_certificate_path' => $path, 'app.https_port' => 8443]);
    }

    /** Plain file read: data providers run before the application boots. */
    private static function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__).'/Fixtures/'.$name);
    }

    public function test_the_page_offers_the_certificate_with_its_fingerprint_without_a_login(): void
    {
        $ca = self::fixture('test-ca.crt');
        $this->useCertificateFile($ca);

        $sha256 = implode(':', str_split(strtoupper(openssl_x509_fingerprint($ca, 'sha256')), 2));

        // The HTTPS link follows the address the device used, whatever network it is on.
        $this->get('http://kiosk-lan.test:8088/certificate')
            ->assertOk()
            ->assertSee('/certificate/download')
            ->assertSee('SJCHS Test CA')
            ->assertSee($sha256)
            ->assertSee('https://kiosk-lan.test:8443/kiosk');
    }

    public function test_the_download_is_the_certificate_in_a_form_devices_install(): void
    {
        $ca = self::fixture('test-ca.crt');
        $this->useCertificateFile($ca);

        $response = $this->get('/certificate/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-x509-ca-cert')
            ->assertDownload('sjchs-ca.crt');

        $this->assertSame(openssl_x509_fingerprint($ca, 'sha256'), openssl_x509_fingerprint($response->getContent(), 'sha256'));
    }

    public static function filesThatAreNotServed(): array
    {
        return [
            'private key only' => [self::FAKE_PRIVATE_KEY],
            'certificate bundled with its private key' => [self::fixture('test-ca.crt').self::FAKE_PRIVATE_KEY],
            'server certificate instead of the CA' => [self::fixture('test-server.crt')],
            'two certificates' => [self::fixture('test-ca.crt').self::fixture('test-server.crt')],
            'not a certificate' => ['hello'],
        ];
    }

    #[DataProvider('filesThatAreNotServed')]
    public function test_anything_but_a_single_ca_certificate_is_refused(string $contents): void
    {
        $this->useCertificateFile($contents);

        $this->get('/certificate/download')->assertNotFound();
        $this->get('/certificate')->assertOk()
            ->assertSee('has not been created on the server yet')
            ->assertDontSee('/certificate/download');
    }

    public function test_a_missing_certificate_file_is_reported_on_the_page(): void
    {
        config(['app.ca_certificate_path' => dirname(__DIR__).'/Fixtures/does-not-exist.crt']);

        $this->get('/certificate/download')->assertNotFound();
        $this->get('/certificate')->assertOk()->assertSee('has not been created on the server yet');
    }
}
