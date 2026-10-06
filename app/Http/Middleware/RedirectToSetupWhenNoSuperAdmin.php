<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A fresh install has no accounts, so nobody could log in (the native-PHP
 * system relied on an environment variable for this). Until a Super Admin
 * exists, the login page sends people to /setup.
 */
class RedirectToSetupWhenNoSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::superAdminExists()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }

    public static function superAdminExists(): bool
    {
        // Once true it stays true, so only cache the positive answer.
        if (Cache::get('setup.superadmin_exists')) {
            return true;
        }

        $exists = Account::query()->where('role', 'superadmin')->where('is_deleted', false)->exists();

        if ($exists) {
            Cache::forever('setup.superadmin_exists', true);
        }

        return $exists;
    }
}
