<?php

namespace App\Support;

use App\Http\Middleware\EnsureAdmin;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Destinazione dei pulsanti «Torna indietro»: riporta sempre alla pagina principale di una sezione.
 *
 * Ordine di scelta: il parametro esplicito `return_to`, poi l'ultima pagina principale aperta in questa sessione
 * (il Referer non è disponibile: l'applicazione invia `Referrer-Policy: no-referrer`) e infine la sezione della risorsa. Sono accettate solo pagine principali dello stesso sito, con i loro filtri: niente redirect esterni,
 * niente pagine di dettaglio (quindi nessun ciclo) e nessuna sezione che l'utente non può aprire.
 */
class SectionReturn
{
    public const string SessionKey = 'section_return';

    /** Pagine principali di sezione per l'area amministrativa e operativa. */
    public const array Homes = [
        'dashboard', 'orders.incoming', 'orders.in-progress', 'orders.history', 'pickups.index', 'pickups.day',
        'messages.index', 'notifications.index', 'customers.index', 'users.index', 'rates.index', 'balance.index',
        'pending.index', 'reports.index', 'recipient-incidents.index', 'settings.index', 'rider-operations.index',
        'rider-operations.mine', 'rider-operations.detail', 'rider-operations.zone-orders',
    ];

    /** @param list<string> $homes */
    public static function resolve(Request $request, string $fallback, array $homes = self::Homes): string
    {
        foreach ([$request->query('return_to'), $request->hasSession() ? $request->session()->get(self::SessionKey) : null] as $candidate) {
            if (is_string($candidate) && ($url = self::accept($request, $candidate, $homes))) {
                return $url;
            }
        }

        return $fallback;
    }

    /** @param list<string> $homes */
    private static function accept(Request $request, string $candidate, array $homes): ?string
    {
        if (strlen($candidate) > 2000 || preg_match('/[\x00-\x1F\\\\]/', $candidate)) {
            return null;
        }
        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['host']) && (strcasecmp($parts['host'], $request->getHost()) !== 0 || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true))) {
            return null;
        }
        if (! isset($parts['host']) && (isset($parts['scheme']) || ! str_starts_with($candidate, '/') || str_starts_with($candidate, '//'))) {
            return null;
        }
        $path = '/'.trim($parts['path'] ?? '/', '/');
        foreach ($homes as $name) {
            $route = Route::getRoutes()->getByName($name);
            if ($route && '/'.trim($route->uri(), '/') === $path && self::allowed($request, $route)) {
                return $path.(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
            }
        }

        return null;
    }

    /** Le sezioni riservate all'Admin non vengono proposte agli altri profili. */
    private static function allowed(Request $request, RouteDefinition $route): bool
    {
        return $request->user()?->role === UserRole::Admin || ! in_array(EnsureAdmin::class, $route->gatherMiddleware(), true);
    }
}
