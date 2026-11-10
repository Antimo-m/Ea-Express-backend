<?php

namespace App\Providers;

use App\Support\AccountSessionRevocation;
use App\Support\AuthUserProvider;
use App\Support\NotificationInbox;
use App\Support\PostalCodeResolver;
use App\Support\SecurityEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PostalCodeResolver::class);
    }

    public function boot(): void
    {
        Auth::provider('auth-security', fn (Application $app, array $config): AuthUserProvider => new AuthUserProvider($app['hash'], $config['model']));
        Event::listen(Login::class, function (Login $event): void {
            app(AccountSessionRevocation::class)->authorize(request(), $event->user, $event->guard);
        });
        Paginator::useBootstrapFive();
        Event::listen(Failed::class, function (Failed $event): void {
            SecurityEvent::record('login_failed', $event->user?->getAuthIdentifier());
        });

        RateLimiter::for('customer-api', fn (Request $request) => Limit::perMinute(120)->by('customer-api:'.($request->user('customer')?->id ?? $request->ip())));
        \Illuminate\Support\Facades\View::composer('components.navigation.header', function (View $view) {
            $view->with('unreadNotifications', auth()->user() ? app(NotificationInbox::class)->unreadCount(auth()->user()) : 0);
        });
        foreach (['public-pages' => 120, 'tracking' => 30, 'conversation' => 30, 'conversation-write' => 5] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)->by($name.':'.$request->ip()));
        }
        RateLimiter::for('profile-update', fn (Request $request) => [Limit::perMinute(5)->by('profile-user:'.$request->user()->id), Limit::perMinute(20)->by('profile-ip:'.$request->ip())]);
        RateLimiter::for('realtime-writes', fn (Request $request) => Limit::perMinute(60)->by('realtime-writes:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by('writes:'.($request->user()?->id ?? $request->ip())));
    }
}
