<?php

use App\Http\Middleware\EnsureAccountSessionVersion;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureStaffAuthentication;
use App\Http\Middleware\LimitRequestInput;
use App\Http\Middleware\PreservePageNavigation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustAuthenticationProxies;
use App\Support\CanonicalUrl;
use App\Support\SecurityEvent;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustAuthenticationProxies::class);
        $middleware->trustHosts(at: fn (): array => CanonicalUrl::trustedHostPatterns(), subdomains: false);
        $middleware->prepend([LimitRequestInput::class, SecurityHeaders::class]);
        $middleware->trimStrings(except: ['price', 'parcel_value', 'carrier_cost', 'amount']);
        $middleware->web(append: [EnsureAccountSessionVersion::class, EnsureActiveAccount::class, EnsureStaffAuthentication::class], replace: [StartSession::class => PreservePageNavigation::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureStaffAuthentication::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): Response {
            SecurityEvent::record('rate_limited');
            $headers = $exception->getHeaders();
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
            $minutes = (int) ceil($retryAfter / 60);
            $unit = $minutes === 1 ? 'minuto' : 'minuti';
            $message = $retryAfter < 60 ? "Troppe richieste. Potrai riprovare tra {$retryAfter} secondi." : "Troppe richieste. Potrai riprovare tra {$minutes} {$unit}.";

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message, 'retry_after' => $retryAfter], 429, $headers);
            }

            return response()->view('auth.rate-limited', compact('message', 'retryAfter'), 429, $headers);
        });
        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?Response {
            if (! ($request->is('api/*') || $request->expectsJson()) || ! ($exception->getPrevious() instanceof ModelNotFoundException || $request->route() === null)) {
                return null;
            }

            return response()->json(['message' => 'Risorsa non disponibile.'], 404);
        });
        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request): ?Response {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json(['message' => 'Operazione non consentita.'], 405, $exception->getHeaders());
        });
        $exceptions->dontFlash(['cf-turnstile-response', 'token', 'checkout_token', 'code', 'phone', 'password', 'password_confirmation', 'current_password']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
