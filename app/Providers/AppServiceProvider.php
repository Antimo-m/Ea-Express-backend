<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        \Illuminate\Support\Facades\View::composer('components.navigation.header', function (View $view) {
            $view->with('unreadNotifications', auth()->user()?->unreadNotifications()->count() ?? 0);
        });
        foreach (['public-pages' => 120, 'registration' => 5, 'recovery' => 5, 'reset-password' => 10, 'login-ip' => 20, 'tracking' => 30, 'conversation' => 30, 'conversation-write' => 5] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)->by($name.':'.$request->ip()));
        }
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by('writes:'.($request->user()?->id ?? $request->ip())));
    }
}
