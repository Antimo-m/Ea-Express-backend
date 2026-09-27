<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

class PreservePageNavigation extends StartSession
{
    protected function storeCurrentUrl(Request $request, $session): void
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->routeIs('booking-rules')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
