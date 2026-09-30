<?php

namespace App\Actions;

use App\Models\User;
use App\Support\SecurityEvent;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyEmailOtp
{
    public function handle(User $user, string $code): void
    {
        $error = DB::transaction(function () use ($user, $code): ?string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->role === UserRole::Rider, 403);
            if ($locked->email_verified_at || ! $locked->email_otp_hash) {
                return 'Richiedi un nuovo codice di verifica.';
            }
            if (! $locked->email_otp_expires_at || $locked->email_otp_expires_at->lessThanOrEqualTo(now())) {
                $locked->email_otp_hash = null;
                $locked->save();

                return 'Il codice è scaduto. Richiedine uno nuovo.';
            }
            if ($locked->email_otp_attempts >= 5) {
                return 'Troppi tentativi. Richiedi un nuovo codice.';
            }
            $challenge = session()->get('email_otp_challenge');
            if (! is_array($challenge) || ($challenge['user_id'] ?? null) !== $locked->id || ! is_string($challenge['fingerprint'] ?? null) || ! hash_equals(hash('sha256', $locked->email_otp_hash), $challenge['fingerprint'])) {
                return 'Richiedi un codice da questa sessione.';
            }
            if (! Hash::check(SendEmailOtp::digest($locked, $code), $locked->email_otp_hash)) {
                $locked->email_otp_attempts++;
                if ($locked->email_otp_attempts >= 5) {
                    $locked->email_otp_hash = null;
                }
                $locked->save();

                return 'Codice non corretto. Dopo cinque errori devi richiederne uno nuovo.';
            }
            $locked->email_verified_at = now();
            $locked->email_otp_hash = null;
            $locked->email_otp_expires_at = null;
            $locked->email_otp_attempts = 0;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($locked, $locked, 'rider.email_verified', null, ['email_verified_at' => $locked->email_verified_at]);

            return null;
        }, 3);
        if ($error !== null) {
            SecurityEvent::record('otp_failed', $user->id);
            throw ValidationException::withMessages(['code' => $error]);
        }
        session()->forget('email_otp_challenge');
    }
}
