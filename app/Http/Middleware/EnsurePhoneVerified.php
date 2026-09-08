<?php

namespace App\Http\Middleware;

use App\Support\PhoneVerification;
use App\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhoneVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (PhoneVerification::enabled() && $user && $user->role !== UserRole::Admin && ! $user->phone_verified_at) {
            if ($request->expectsJson()) {
                abort(403, 'Verifica prima il numero di cellulare.');
            }

            return redirect()->route('phone.notice');
        }

        return $next($request);
    }
}
