<?php

namespace App\Http\Middleware;

use App\Support\AuthProtection;
use App\Support\AuthSecurityStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectAuthentication
{
    public function __construct(private AuthProtection $protection, private AuthSecurityStore $store) {}

    public function handle(Request $request, Closure $next, string $operation = 'login'): Response
    {
        if ($operation === 'login') {
            $this->protection->inspect($request);
        } else {
            $user = $request->is('api/v1/customer/*') ? $request->user('customer') : $request->user('web');
            $identifier = $operation === 'register' ? $request->ip() : ($user?->email ?? $request->ip());
            $this->store->consume(AuthSecurityStore::key($operation.':account', (string) $identifier), config('auth_security.endpoints.account_attempts'), config('auth_security.endpoints.window_seconds'));
            $this->store->consume(AuthSecurityStore::key($operation.':ip', (string) $request->ip()), config('auth_security.endpoints.ip_attempts'), config('auth_security.endpoints.window_seconds'));
        }

        return $next($request);
    }
}
