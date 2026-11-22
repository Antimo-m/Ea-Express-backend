<?php

namespace App\Http\Middleware;

use App\Support\SectionReturn;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

class PreservePageNavigation extends StartSession
{
    public function __construct(SessionManager $manager, Factory $cache)
    {
        parent::__construct($manager, fn (): Factory => $cache);
    }

    protected function storeCurrentUrl(Request $request, $session): void
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->routeIs('booking-rules')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
        // Ultima pagina principale di sezione aperta, con i suoi filtri: è il contesto dei pulsanti «Torna indietro».
        if ($request->isMethod('GET') && $request->routeIs(...SectionReturn::Homes)) {
            $session->put(SectionReturn::SessionKey, substr($request->getRequestUri(), 0, 2000));
        }
    }
}
