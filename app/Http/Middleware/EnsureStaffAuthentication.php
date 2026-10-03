<?php

namespace App\Http\Middleware;

use App\StaffAuthenticationState;
use App\Support\SecurityEvent;
use App\Support\StaffAuthentication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffAuthentication
{
    public function __construct(private StaffAuthentication $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();
        if (! $user?->isStaff()) {
            return $next($request);
        }
        if ($guard->viaRemember() && ! $request->session()->has('staff_authentication')) {
            $request->session()->regenerate(true);
            $this->authentication->begin($request, $user);
        }
        $state = $this->authentication->state($request, $user);
        if ($request->routeIs('logout') || $state === StaffAuthenticationState::FullyAuthenticated) {
            return $next($request);
        }
        if ($state === StaffAuthenticationState::PrimaryAuthenticated && $request->routeIs('email-otp.notice', 'email-otp.send', 'email-otp.verify')) {
            return $next($request);
        }
        SecurityEvent::record('partial_auth_access_denied', $user->id);
        if ($state === StaffAuthenticationState::Guest) {
            $this->authentication->invalidateChallenge($request);
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        abort_if($request->expectsJson() || $request->is('api/*'), 403, 'Completa prima la verifica di accesso.');

        return redirect()->route($state === StaffAuthenticationState::Guest ? 'login' : 'email-otp.notice');
    }
}
