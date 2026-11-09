<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class StaffDeviceProof
{
    public function allows(Request $request, string $email): bool
    {
        if (! config('auth_security.device.enabled')) {
            return false;
        }
        $value = $request->cookie(config('auth_security.device.cookie'));
        if (! is_string($value) || strlen($value) > 1024 || ! str_contains($value, '.')) {
            return false;
        }
        [$payload, $signature] = explode('.', $value, 2);
        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)) {
            return false;
        }
        $data = json_decode(base64_decode($payload, true) ?: '', true);

        return is_array($data) && is_int($data['expires_at'] ?? null) && $data['expires_at'] > now()->timestamp
            && is_string($data['account'] ?? null) && hash_equals(AuthSecurityStore::key('device:account', AuthSecurityStore::email($email)), $data['account']);
    }

    public function issue(User $user): void
    {
        if (! config('auth_security.device.enabled')) {
            return;
        }
        $seconds = config('auth_security.device.ttl_seconds');
        $payload = base64_encode(json_encode([
            'account' => AuthSecurityStore::key('device:account', AuthSecurityStore::email($user->email)),
            'expires_at' => now()->timestamp + $seconds, 'nonce' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
        $proof = $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
        Cookie::queue(Cookie::make(config('auth_security.device.cookie'), $proof, (int) ceil($seconds / 60), '/', null, (bool) config('session.secure'), true, false, 'strict'));
    }
}
