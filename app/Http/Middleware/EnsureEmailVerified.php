<?php

namespace App\Http\Middleware;

use App\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === UserRole::Rider && ! $request->user()->email_verified_at) {
            abort_if($request->expectsJson(), 403, 'Verifica prima il tuo indirizzo email.');

            return redirect()->route('email-otp.notice');
        }

        return $next($request);
    }
}
