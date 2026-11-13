<?php

namespace App\Support;

use App\Models\User;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Devices verified with an email OTP by riders and customers. Admins never receive a trusted device.
 */
class TrustedRiderDevices
{
    public function consume(Request $request, User $user): bool
    {
        $settings = $this->settings($user);
        if (! $settings || ! $user->is_active || ! $user->email_verified_at
            || $request->attributes->get('auth_risky') || $request->attributes->get('auth_ip_limited') || $request->attributes->get('auth_device_recovery')) {
            return false;
        }
        $credential = $request->cookie($settings['cookie']);
        if (! is_string($credential) || ! preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/D', $credential, $parts)) {
            return false;
        }

        return DB::transaction(function () use ($request, $user, $parts, $settings): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $device = DB::table('trusted_rider_devices')->where('slug', $parts[1])->where('user_id', $locked->id)->lockForUpdate()->first();
            $authentication = app(StaffAuthentication::class);
            if (! $device || $device->revoked_at || now()->gte($device->expires_at)
                || ! hash_equals($device->token_hash, hash('sha256', $parts[2]))
                || ! hash_equals($device->credential_proof, $authentication->credentialProof($locked))
                || ! hash_equals($authentication->credentialProof($user), $authentication->credentialProof($locked))) {
                return false;
            }
            $secret = bin2hex(random_bytes(32));
            DB::table('trusted_rider_devices')->where('slug', $device->slug)->update(['token_hash' => hash('sha256', $secret), 'last_used_at' => now()]);
            $request->attributes->set('trusted_rider_device', $device->slug);
            $this->cookie($settings['cookie'], $device->slug.'.'.$secret, max(1, now()->diffInMinutes($device->expires_at)));
            SecurityEvent::record('trusted_rider_device_used', $user->id);

            return true;
        }, 3);
    }

    public function issue(Request $request, User $user): void
    {
        $settings = $this->settings($user);
        if (! $settings) {
            return;
        }
        $proof = app(StaffAuthentication::class)->credentialProof($user);
        if ($slug = $request->attributes->get('trusted_rider_device')) {
            DB::table('trusted_rider_devices')->where('slug', $slug)->where('user_id', $user->id)->whereNull('revoked_at')->update(['credential_proof' => $proof]);

            return;
        }
        $slug = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $seconds = max(60, (int) $settings['ttl_seconds']);
        DB::table('trusted_rider_devices')->insert([
            'slug' => $slug, 'user_id' => $user->id, 'token_hash' => hash('sha256', $secret), 'credential_proof' => $proof,
            'authorized_at' => now(), 'last_used_at' => now(), 'expires_at' => now()->addSeconds($seconds), 'revoked_at' => null,
        ]);
        $this->cookie($settings['cookie'], $slug.'.'.$secret, (int) ceil($seconds / 60));
        SecurityEvent::record('trusted_rider_device_authorized', $user->id);
    }

    public function revoke(User $user): void
    {
        DB::table('trusted_rider_devices')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @return array{enabled: bool, cookie: string, ttl_seconds: int}|null */
    private function settings(User $user): ?array
    {
        $settings = match ($user->role) {
            UserRole::Rider => config('auth_security.trusted_rider'),
            UserRole::Customer => config('auth_security.trusted_customer'),
            default => null,
        };

        return is_array($settings) && $settings['enabled'] ? $settings : null;
    }

    private function cookie(string $name, string $value, float|int $minutes): void
    {
        Cookie::queue(Cookie::make($name, $value, (int) $minutes, '/', null,
            app()->environment('production') || (bool) config('session.secure'), true, false, 'strict'));
    }
}
