<?php

namespace App\Http\Middleware;

use App\Support\AccessGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durante una pausa di sicurezza l'account non raggiunge alcuna risorsa protetta.
 * Resta possibile uscire dall'account. I visitatori non autenticati non sono coinvolti.
 */
class EnforceAccessTimeout
{
    public function __construct(private AccessGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->is('api/v1/customer/*') ? Auth::guard('customer')->user() : Auth::guard('web')->user();
        if ($user && ! $request->is('logout', 'api/v1/customer/auth/logout')) {
            $seconds = $this->guard->remainingForUser((int) $user->getAuthIdentifier());
            if ($seconds > 0) {
                return $this->guard->timeoutResponse($request, $seconds);
            }
        }

        return $next($request);
    }
}
