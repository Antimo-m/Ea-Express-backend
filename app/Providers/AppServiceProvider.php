<?php

namespace App\Providers;

use App\Models\User;
use App\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
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
        RateLimiter::for('customer-api', fn (Request $request) => Limit::perMinute(120)->by('customer-api:'.($request->user('customer')?->id ?? $request->ip())));
        RateLimiter::for('customer-login', fn (Request $request) => [Limit::perMinute(20)->by('customer-ip:'.$request->ip()), Limit::perMinute(5)->by('customer-login:'.hash('sha256', strtolower((string) $request->input('email'))).'|'.$request->ip())]);
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            if ($user->role === UserRole::Customer) {
                return rtrim(config('customer.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
            }

            return route('password.reset', ['token' => $token, 'email' => $user->email]);
        });
        \Illuminate\Support\Facades\View::composer('components.navigation.header', function (View $view) {
            $view->with('unreadNotifications', auth()->user()?->unreadNotifications()->count() ?? 0);
        });
        foreach (['public-pages' => 120, 'registration' => 5, 'recovery' => 5, 'reset-password' => 10, 'login-ip' => 20, 'tracking' => 30, 'conversation' => 30, 'conversation-write' => 5] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)->by($name.':'.$request->ip()));
        }
        RateLimiter::for('otp-send', fn (Request $request) => [Limit::perHour(3)->by('otp-send-user:'.$request->user()->id), Limit::perHour(15)->by('otp-send-ip:'.$request->ip())]);
        RateLimiter::for('otp-check', fn (Request $request) => [Limit::perMinute(5)->by('otp-check:'.$request->user()->id), Limit::perHour(20)->by('otp-check-hour:'.$request->user()->id)]);
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by('writes:'.($request->user()?->id ?? $request->ip())));
    }
}
