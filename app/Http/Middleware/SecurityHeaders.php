<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $geolocation = $request->user()?->canOperateDeliveries() ? '(self)' : '()';
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation='.$geolocation);
        if ($request->user() || $request->is('login', 'register', '*password*', 'track/*', 'conversation/*', 'api/v1/customer/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (app()->isProduction()) {
            $socketOrigin = (config('realtime.scheme') === 'https' ? 'wss://' : 'ws://').config('realtime.host').':'.config('realtime.port');
            $tilesHost = parse_url(config('tracking.tiles_url'), PHP_URL_HOST);
            $tilesOrigin = $tilesHost && preg_match('/^[a-zA-Z0-9.-]+$/D', $tilesHost) ? 'https://'.$tilesHost : '';
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: {$tilesOrigin}; font-src 'self'; connect-src 'self' {$socketOrigin}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
            if ($request->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
        }

        return $response;
    }
}
