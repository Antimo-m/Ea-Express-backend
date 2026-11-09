<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustAuthenticationProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $proxies = array_values(array_filter(config('auth_security.trusted_proxies'), fn (string $proxy): bool => ! in_array($proxy, ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0'], true)));
        $request->setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    }
}
