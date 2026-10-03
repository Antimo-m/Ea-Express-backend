<?php

namespace App\Actions;

use App\Models\User;
use App\StaffAuthenticationState;
use App\Support\SecurityEvent;
use App\Support\StaffAuthentication;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyEmailOtp
{
    public function handle(User $user, #[\SensitiveParameter] string $code): void
    {
        $event = 'otp_failed';
        $error = DB::transaction(function () use ($user, $code, &$event): ?string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->role === UserRole::Rider, 403);
            if ($locked->email_verified_at || ! $locked->email_otp_hash) {
                $event = 'otp_replay_or_invalid_challenge';

                return 'Richiedi un nuovo codice di verifica.';
            }
            if (! $locked->email_otp_expires_at || $locked->email_otp_expires_at->lessThanOrEqualTo(now())) {
                $event = 'otp_expired';
                $locked->email_otp_hash = null;
                $locked->email_otp_expires_at = null;
                $locked->save();

                return 'Il codice è scaduto. Richiedine uno nuovo.';
            }
            if ($locked->email_otp_attempts >= 5) {
                return 'Troppi tentativi. Richiedi un nuovo codice.';
            }
            $challenge = session()->get('email_otp_challenge');
            $authentication = app(StaffAuthentication::class);
            if ($authentication->state(request(), $locked) !== StaffAuthenticationState::PrimaryAuthenticated
                || ! is_array($challenge)
                || ($challenge['type'] ?? null) !== 'email_verification'
                || ! is_string($challenge['challenge_id'] ?? null)
                || ! is_string($challenge['session_binding'] ?? null)
                || ! hash_equals($authentication->binding(request()), $challenge['session_binding'])
                || ($challenge['expires_at'] ?? 0) <= now()->timestamp
                || ($challenge['user_id'] ?? null) !== $locked->id
                || ! is_string($challenge['fingerprint'] ?? null)
                || ! hash_equals(hash('sha256', $locked->email_otp_hash), $challenge['fingerprint'])) {
                $event = 'otp_challenge_mismatch';

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
            SecurityEvent::record($event, $user->id);
            throw ValidationException::withMessages(['code' => $error]);
        }
        session()->forget('email_otp_challenge');
        app(StaffAuthentication::class)->factorVerified(request(), $user->refresh());
        SecurityEvent::record('otp_verified', $user->id);
    }
}
