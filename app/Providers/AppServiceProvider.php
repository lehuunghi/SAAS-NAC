<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('account-security', fn (Request $request) => Limit::perMinute(5)->by('account-security:'.$request->user()?->id));
        foreach (['login' => [6, 1], 'registration' => [3, 60], 'tenant-sites' => [30, 1], 'tenant-install' => [5, 1], 'tenant-quotes' => [10, 60], 'tenant-orders' => [20, 1], 'tenant-support' => [10, 60], 'tenant-team' => [10, 1], 'platform-admin' => [120, 1], 'platform-install' => [5, 1], 'platform-payment' => [10, 1]] as $name => [$max, $minutes]) {
            RateLimiter::for($name, fn (Request $request) => ($minutes === 60 ? Limit::perHour($max) : Limit::perMinute($max))->by($name.':'.($request->user()?->id ?? $request->ip())));
        }
    }
}
