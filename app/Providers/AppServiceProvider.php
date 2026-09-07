<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        foreach (['public-pages' => 120, 'registration' => 5, 'recovery' => 5, 'reset-password' => 10, 'login-ip' => 20, 'tracking' => 30] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)->by($name.':'.$request->ip()));
        }
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by('writes:'.($request->user()?->id ?? $request->ip())));
    }
}
