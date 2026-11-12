<?php

namespace App\Http\Middleware;

use App\Support\AccountSessionRevocation;
use App\Support\SecurityEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSessionVersion
{
    public function __construct(private AccountSessionRevocation $revocation) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach (['web', 'customer'] as $name) {
            $guard = Auth::guard($name);
            $user = $guard->user();
            if (! $user || $this->revocation->matches($request, $user, $name)) {
                continue;
            }
            SecurityEvent::record('obsolete_session_rejected', $user->id);
            $this->revocation->invalidateCurrent($request);
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Sessione revocata. Accedi nuovamente al tuo account.'], $name === 'customer' ? 401 : 403);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }
}
