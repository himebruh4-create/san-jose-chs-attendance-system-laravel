<?php

namespace App\Providers;

use App\Support\SchoolBranding;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The school's name and logo on every page and printout (read once per request).
        View::composer('*', function ($view): void {
            $view->with('school', SchoolBranding::current());
        });
    }
}
