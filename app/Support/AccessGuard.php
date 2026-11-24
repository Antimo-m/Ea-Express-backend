<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta gli accessi negati alle risorse protette e sospende temporaneamente chi insiste.
 *
 * Vengono conteggiati soltanto i dinieghi di autorizzazione (cancello del dominio, ruolo o permesso
 * mancante): 404 ordinari, errori applicativi, sessioni scadute e verifiche di accesso incomplete
 * non contano. Gli utenti autenticati sono identificati dall'account, i visitatori respinti dal
 * cancello dall'indirizzo IP già risolto da NGINX: chi condivide la rete con loro non viene bloccato.
 */
class AccessGuard
{
    public const string DeniedMessage = 'Non è possibile completare la richiesta.';

    private const string InviteMessage = 'Per entrare apri il link d’invito completo che hai ricevuto.';

    /**
     * Nasconde una risorsa altrui come un normale 404, ma conta il tentativo tra gli accessi negati.
     */
    public static function hideUnless(bool $allowed): void
    {
        if (! $allowed) {
            throw (new AuthorizationException)->withStatus(404);
        }
    }

    /**
     * Registra un accesso negato; restituisce i secondi di pausa da applicare (0 se nessuna).
     */
    public function recordDenial(Request $request, string $source, bool $networkOnly = false): int
    {
        $actor = $this->actor($request, $networkOnly);
        $remaining = $this->remaining($actor);
        if ($remaining > 0) {
            return $remaining;
        }
        $settings = config('auth_security.access');
        $attempts = RateLimiter::hit($this->key('denied', $actor), (int) $settings['window_seconds']);
        SecurityEvent::record('access_denied', $this->userId($actor), ['source' => $source, 'attempt' => $attempts] + $this->requestContext($request));
        if ($attempts < (int) $settings['attempts']) {
            return 0;
        }
        RateLimiter::clear($this->key('denied', $actor));
        $seconds = (int) $settings['timeout_seconds'];
        Cache::put($this->key('timeout', $actor), now()->addSeconds($seconds)->getTimestamp(), $seconds);
        SecurityEvent::record('access_timeout', $this->userId($actor), ['source' => $source, 'seconds' => $seconds] + $this->requestContext($request));

        return $seconds;
    }

    /** Secondi di pausa ancora attivi per l'account autenticato della richiesta. */
    public function remainingForUser(int $userId): int
    {
        return $this->remaining('user:'.$userId);
    }

    /** Nomina l'account solo se la pausa riguarda un account: chi è fermo al cancello è sospeso per indirizzo IP. */
    public function timeoutResponse(Request $request, int $seconds, bool $networkOnly = false): Response
    {
        $subject = $this->userId($this->actor($request, $networkOnly)) !== null ? 'Il tuo account' : 'L’accesso';
        $minutes = max(1, (int) ceil((int) config('auth_security.access.timeout_seconds') / 60));
        $message = $subject.' è stato sospeso per '.$minutes.' '.($minutes === 1 ? 'minuto' : 'minuti').' per troppi tentativi errati, ritorna tra poco.';
        $headers = ['Retry-After' => (string) $seconds, 'Cache-Control' => 'no-store, private'];
        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'code' => 'access_suspended', 'retry_after' => $seconds], 429, $headers);
        }

        return response()->view('errors.unavailable', ['title' => 'Accesso momentaneamente sospeso', 'message' => $message, 'blocked' => true], 429, $headers);
    }

    /**
     * Con `$blocked` (cancello) la pagina mostra solo l'errore: ogni collegamento porterebbe di nuovo a una pagina bloccata.
     * Sul portale clienti indica di usare il link d'invito; sul gestionale resta neutra.
     */
    public function deniedResponse(Request $request, int $status = 403, bool $blocked = false): Response
    {
        $headers = ['Cache-Control' => 'no-store, private'];
        if ($this->wantsJson($request)) {
            return response()->json(['message' => $status === 404 ? 'Risorsa non disponibile.' : self::DeniedMessage], $status, $headers);
        }

        $data = $blocked
            ? ['blocked' => true, 'message' => $this->isPortalHost((string) $request->server('EA_ACCESS_GATE')) ? self::InviteMessage : null]
            : ['homeUrl' => $this->homeUrl($request)];

        return response()->view('errors.unavailable', $data, $status, $headers);
    }

    /** Pagina iniziale consentita per l'host della richiesta: portale clienti o gestionale. */
    public function homeUrl(Request $request): string
    {
        if ($this->isPortalHost($request->getHost())) {
            return rtrim((string) config('customer.frontend_url'), '/').'/';
        }

        return route('dashboard');
    }

    private function isPortalHost(string $host): bool
    {
        return $host !== '' && strcasecmp((string) parse_url((string) config('customer.frontend_url'), PHP_URL_HOST), $host) === 0;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function actor(Request $request, bool $networkOnly): string
    {
        if (! $networkOnly) {
            $user = $request->is('api/v1/customer/*') ? Auth::guard('customer')->user() : Auth::guard('web')->user();
            if ($user) {
                return 'user:'.$user->getAuthIdentifier();
            }
        }

        return 'ip:'.$request->ip();
    }

    private function remaining(string $actor): int
    {
        $until = Cache::get($this->key('timeout', $actor));

        // Redis restituisce i numeri come testo: un controllo is_int ignorerebbe la pausa in produzione.
        return is_numeric($until) ? max(0, (int) $until - now()->getTimestamp()) : 0;
    }

    private function key(string $kind, string $actor): string
    {
        return AuthSecurityStore::key('access-'.$kind, $actor);
    }

    private function userId(string $actor): ?int
    {
        return str_starts_with($actor, 'user:') ? (int) substr($actor, 5) : null;
    }

    /** @return array{method: string, host: string, section: string} */
    private function requestContext(Request $request): array
    {
        $path = (string) ($request->server('EA_ACCESS_GATE_PATH') ?: $request->path());
        $section = strtok(trim($path, '/'), '/');

        return [
            'method' => $request->getMethod(),
            'host' => $request->getHost(),
            'section' => is_string($section) && preg_match('/^[a-z0-9-]{1,40}$/', $section) ? $section : '-',
        ];
    }
}
