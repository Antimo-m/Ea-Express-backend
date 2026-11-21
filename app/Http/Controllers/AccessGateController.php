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
    /** Destinazioni Fetch Metadata dei file caricati da una pagina (icone, stili, script, font, manifest). */
    private const array SubresourceDestinations = ['image', 'style', 'script', 'font', 'manifest', 'audio', 'video', 'track', 'worker', 'sharedworker', 'serviceworker'];

    /** Estensioni dei file statici, per i browser che non inviano Sec-Fetch-Dest. */
    private const string StaticFilePattern = '/\.(?:ico|png|jpe?g|gif|svg|webp|avif|css|js|mjs|map|woff2?|ttf|otf|webmanifest|json|txt|xml)$/i';

    public function __invoke(Request $request, AccessGuard $guard): Response
    {
        abort_if(blank($request->server('EA_ACCESS_GATE')), 404);
        // Ogni apertura di pagina porta con sé icona, stili e script, tutti respinti dal cancello: contarli moltiplicava
        // i tentativi (3-5 per pagina) e faceva scattare la pausa dopo una o due visite. Il file resta negato, ma senza
        // conteggio, senza voce nel registro di sicurezza e senza pagina d'errore da generare.
        if ($this->isSubresource($request)) {
            return response('', 403, ['Cache-Control' => 'no-store, private', 'Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $timeout = $guard->recordDenial($request, 'gate', networkOnly: true);

        return $timeout > 0 ? $guard->timeoutResponse($request, $timeout) : $guard->deniedResponse($request, blocked: true);
    }

    private function isSubresource(Request $request): bool
    {
        $destination = strtolower((string) $request->headers->get('Sec-Fetch-Dest'));
        if ($destination !== '') {
            return in_array($destination, self::SubresourceDestinations, true);
        }
        $path = (string) ($request->server('EA_ACCESS_GATE_PATH') ?: $request->path());

        return $request->isMethod('GET') && preg_match(self::StaticFilePattern, (string) parse_url($path, PHP_URL_PATH)) === 1;
    }
}
