<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->useLangPath(resource_path('lang'));
    }

    public function boot(): void
    {
        // Validation is part of the application's Portuguese user interface.
        $this->app->setLocale('pt_BR');
        RateLimiter::for('authentication', fn (Request $request) => [
            Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
            Limit::perMinute(5)->by('auth-account:'.mb_strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()),
        ]);
    }
}
