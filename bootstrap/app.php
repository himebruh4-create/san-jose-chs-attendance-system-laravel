<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RedirectToSetupWhenNoSuperAdmin;
use App\Http\Middleware\RequireKioskDevice;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            // Ends an account's other sessions when its password changes.
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'kiosk' => RequireKioskDevice::class,
            'setup.done' => RedirectToSetupWhenNoSuperAdmin::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->homeRoute()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every data endpoint answers in the {success, message} shape the
        // pages already handle, including for errors.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('data/*', 'kiosk/*') || $request->expectsJson(),
        );
    })->create();
