<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scans are only accepted from a browser the Super Admin authorized as a
 * kiosk (Settings -> Kiosk Devices). In the native-PHP system anyone on the
 * network could post a barcode and record attendance for that person.
 */
class RequireKioskDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = KioskDevice::findByToken($request->cookie(KioskDevice::COOKIE));

        if (! $device) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'title' => 'Kiosk Not Authorized',
                    'message' => 'This device is not authorized to record attendance. Ask the Super Admin to register it as a kiosk.',
                ], 403);
            }

            return response()->view('kiosk.unauthorized', [], 403);
        }

        // Touch at most once a minute.
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
        }

        $request->attributes->set('kiosk_device', $device);

        return $next($request);
    }
}
