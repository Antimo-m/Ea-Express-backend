<?php

namespace App\Http\Controllers;

use App\Support\AccessGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risposta alle richieste respinte dal cancello NGINX dei due domini.
 *
 * NGINX blocca la richiesta prima dell'applicazione e la inoltra qui con il parametro FastCGI
 * EA_ACCESS_GATE, che un client non può impostare (le intestazioni HTTP arrivano come HTTP_*).
 * Senza quel parametro la rotta non esiste: chi la apre direttamente riceve un normale 404.
 */
class AccessGateController extends Controller
{
    public function __invoke(Request $request, AccessGuard $guard): Response
    {
        abort_if(blank($request->server('EA_ACCESS_GATE')), 404);
        $timeout = $guard->recordDenial($request, 'gate', networkOnly: true);

        return $timeout > 0 ? $guard->timeoutResponse($request, $timeout) : $guard->deniedResponse($request, blocked: true);
    }
}
