<?php

namespace App\Http\Middleware;

use App\UserRole;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user()?->is_active && $request->user()->role === UserRole::Admin)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
