<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // The kiosk needs the webcam; nothing needs the microphone or location.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
