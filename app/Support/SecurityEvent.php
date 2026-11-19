<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityEvent
{
    /**
     * @param  array<string, scalar|null>  $context  dati aggiuntivi non sensibili
     */
    public static function record(string $action, ?int $userId = null, array $context = []): void
    {
        $request = request();
        Log::channel('security')->notice('security.'.$action, [
            'user_id' => $userId,
            'ip' => AuthSecurityStore::key('ip', (string) $request->ip()),
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'request_id' => self::requestId(),
            ...$context,
        ]);
    }

    public static function requestId(): string
    {
        $request = request();
        $id = $request->attributes->get('security_request_id');
        if (! is_string($id)) {
            $id = (string) Str::uuid();
            $request->attributes->set('security_request_id', $id);
        }

        return $id;
    }
}
