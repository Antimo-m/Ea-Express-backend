<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CustomerPrivacyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $controller = [
            'name' => config('customer_privacy.controller_name'),
            'address' => config('customer_privacy.controller_address'),
            'email' => config('customer_privacy.contact_email'),
        ];

        return response()->json([
            'version' => config('customer_privacy.version'),
            'reviewed' => config('customer_privacy.reviewed') && collect($controller)->every(fn (mixed $value): bool => filled($value)) && filled(config('customer_privacy.privacy_url')),
            'controller' => $controller,
            'privacy_url' => config('customer_privacy.privacy_url'),
            'optional_categories' => [],
            'cookies' => [
                ['name' => config('session.cookie'), 'purpose' => 'Sessione di accesso e protezione delle richieste.', 'minutes' => config('session.lifetime'), 'session_only' => (bool) config('session.expire_on_close'), 'http_only' => (bool) config('session.http_only')],
                ['name' => 'XSRF-TOKEN', 'purpose' => 'Protezione contro richieste contraffatte (CSRF). Il token non concede accesso all’account.', 'minutes' => config('session.lifetime'), 'session_only' => false, 'http_only' => false],
                ['name' => Auth::guard('customer')->getRecallerName(), 'purpose' => 'Mantiene l’accesso solo se selezioni «Ricordami»; rimosso al logout.', 'minutes' => config('customer_privacy.remember_minutes'), 'session_only' => false, 'http_only' => true],
            ],
            'secure' => config('session.secure'),
            'same_site' => config('session.same_site'),
            'cookie_domain' => config('session.domain'),
        ]);
    }
}
