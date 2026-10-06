<?php

namespace App\Http\Controllers;

use App\Models\KioskDevice;
use App\Support\PersonnelPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Serves personnel photos and kiosk scan photos from the private disk.
 * In the native-PHP system personnel photos sat in a public uploads folder.
 */
class PhotoController extends Controller
{
    public function personnel(Request $request, string $filename)
    {
        // Signed-in staff, or an authorized kiosk (it shows the photo after a scan).
        $allowed = $request->user() || KioskDevice::findByToken($request->cookie(KioskDevice::COOKIE));

        abort_unless($allowed, 403);

        $path = PersonnelPhotos::DIR.'/'.basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function scan(int $id)
    {
        $path = DB::table('scan_photos')->where('id', $id)->value('path');

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
