<?php

namespace App\Http\Controllers;

use App\Support\SchoolBranding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The school logo the Super Admin uploaded (SchoolBranding). Public: the
 * login page and the kiosk show it before anyone signs in. Without an
 * uploaded logo it redirects to the built-in one.
 */
class SchoolLogoController extends Controller
{
    public function __invoke(): BinaryFileResponse|RedirectResponse
    {
        $school = SchoolBranding::current();

        if (! $school['custom_logo']) {
            return redirect()->to(asset(SchoolBranding::DEFAULT_LOGO));
        }

        // The URL carries ?v= (the file name), which changes with every
        // upload, so the browser may keep this copy for a long time.
        return response()->file(Storage::disk('local')->path($school['logo_path']), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
