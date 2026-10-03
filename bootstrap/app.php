<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureStaffAuthentication;
use App\Http\Middleware\LimitRequestInput;
use App\Http\Middleware\PreservePageNavigation;
use App\Http\Middleware\SecurityHeaders;
use App\Support\SecurityEvent;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([LimitRequestInput::class, SecurityHeaders::class]);
        $middleware->web(append: [EnsureActiveAccount::class, EnsureStaffAuthentication::class], replace: [StartSession::class => PreservePageNavigation::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureStaffAuthentication::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): Response {
            SecurityEvent::record('rate_limited');
            $headers = $exception->getHeaders();
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
            $minutes = (int) ceil($retryAfter / 60);
            $unit = $minutes === 1 ? 'minuto' : 'minuti';
            $message = "Troppe richieste. Potrai riprovare tra {$minutes} {$unit}.";

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message, 'retry_after' => $retryAfter], 429, $headers);
            }

            return response()->view('auth.rate-limited', compact('message', 'retryAfter'), 429, $headers);
        });
        $exceptions->dontFlash(['token', 'checkout_token', 'code', 'phone', 'password', 'password_confirmation', 'current_password']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
