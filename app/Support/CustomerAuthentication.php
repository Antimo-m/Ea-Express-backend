<?php

namespace App\Support;

use App\Models\User;
use App\UserRole;
use Illuminate\Http\Request;

/**
 * Tracks a customer whose password was accepted on a device that still has to confirm the email OTP.
 * The customer guard stays unauthenticated until the code is verified.
 */
class CustomerAuthentication
{
    private const SessionKey = 'customer_authentication';

    private const LifetimeMinutes = 30;

    public function begin(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::SessionKey, [
            'user_id' => $user->id,
            'binding' => app(StaffAuthentication::class)->binding($request),
            'credentials' => app(StaffAuthentication::class)->credentialProof($user),
            'started_at' => now()->timestamp,
            'remember' => $remember,
        ]);
    }

    public function pending(Request $request): ?User
    {
        $proof = $request->hasSession() ? $request->session()->get(self::SessionKey) : null;
        if (! is_array($proof) || ! is_int($proof['user_id'] ?? null) || ! is_string($proof['binding'] ?? null) || ! is_string($proof['credentials'] ?? null)
            || ($proof['started_at'] ?? 0) <= now()->subMinutes(self::LifetimeMinutes)->timestamp) {
            return null;
        }
        $user = User::query()->find($proof['user_id']);
        $authentication = app(StaffAuthentication::class);
        if (! $user || ! $user->is_active || $user->role !== UserRole::Customer
            || ! hash_equals($authentication->binding($request), $proof['binding'])
            || ! hash_equals($authentication->credentialProof($user), $proof['credentials'])) {
            return null;
        }

        return $user;
    }

    public function remember(Request $request): bool
    {
        return (bool) $request->session()->get(self::SessionKey.'.remember', false);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SessionKey);
    }

    public static function maskedEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(1, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }
}
