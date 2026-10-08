<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Settings -> Kiosk Devices: authorizes the browser the Super Admin is
 * using as an attendance kiosk, or revokes a kiosk.
 */
class KioskDeviceController extends Controller
{
    public function register(Request $request)
    {
        $name = trim((string) $request->input('name'));

        if ($name === '') {
            $name = 'Kiosk '.now()->format('M j, Y g:i A');
        }

        [$device, $token] = KioskDevice::register(mb_substr($name, 0, 100), $request->user()->email);

        Audit::log('kiosk.registered', 'kiosk_device', $device->id, ['name' => $device->name, 'ip' => $request->ip()]);

        // Five years, HttpOnly, SameSite=Lax; encrypted like every cookie.
        $cookie = Cookie::make(KioskDevice::COOKIE, $token, 60 * 24 * 365 * 5, null, null, $request->isSecure(), true, false, 'lax');

        return redirect()->route('superadmin.settings', ['tab' => 'kiosk'])
            ->with('message_type', 'success')
            ->with('message', "This browser is now the kiosk \"{$device->name}\". Log out and open the kiosk page to start scanning.")
            ->withCookie($cookie);
    }

    public function revoke(Request $request)
    {
        $device = KioskDevice::query()->whereKey((int) $request->input('id'))->whereNull('revoked_at')->first();

        if (! $device) {
            return redirect()->route('superadmin.settings', ['tab' => 'kiosk'])
                ->with('message', 'Kiosk not found or already revoked.')->with('message_type', 'error');
        }

        $device->forceFill(['revoked_at' => now()])->save();

        Audit::log('kiosk.revoked', 'kiosk_device', $device->id, ['name' => $device->name]);

        return redirect()->route('superadmin.settings', ['tab' => 'kiosk'])
            ->with('message', "Kiosk \"{$device->name}\" can no longer record attendance.")->with('message_type', 'success');
    }
}
