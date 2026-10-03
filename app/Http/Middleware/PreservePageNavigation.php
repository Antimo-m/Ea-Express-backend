<?php

namespace App\Http\Middleware;

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
    }
}
