<?php

namespace App\Http\Middleware;

use App\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('customer');
        abort_unless($user && $user->is_active, 401, 'Accedi nuovamente al portale.');
        abort_unless($user->role === UserRole::Customer, 403, 'Accesso riservato ai clienti.');

        return $next($request);
    }
}
