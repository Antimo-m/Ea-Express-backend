<?php

namespace App\Actions;

use App\Models\User;
use App\StaffAuthenticationState;
use App\Support\AuthSecurityMonitor;
use App\Support\AuthSecurityStore;
use App\Support\SecurityEvent;
use App\Support\StaffAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyEmailOtp
{
    public function handle(User $user, #[\SensitiveParameter] string $code): void
    {
        $store = app(AuthSecurityStore::class);
        $store->consume(AuthSecurityStore::key('otp:check:account', AuthSecurityStore::email($user->email)), config('auth_security.otp.account_attempts'), config('auth_security.otp.window_seconds'));
        $store->consume(AuthSecurityStore::key('otp:check:ip', (string) request()->ip()), config('auth_security.otp.ip_attempts'), config('auth_security.otp.window_seconds'));
        $event = 'otp_failed';
        $error = DB::transaction(function () use ($user, $code, &$event): ?string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->isStaff(), 403);
            if (! $locked->email_otp_hash) {
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
            if ($locked->email_otp_attempts >= config('auth_security.otp.attempts')) {
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
                if ($locked->email_otp_attempts >= config('auth_security.otp.attempts')) {
                    $locked->email_otp_hash = null;
                    $locked->email_otp_expires_at = null;
                    app(AuthSecurityMonitor::class)->alert('staff_otp_exhausted', $locked->email);
                }
                $locked->save();

                return 'Codice non corretto. Dopo cinque errori devi richiederne uno nuovo.';
            }
            $locked->email_verified_at ??= now();
            $locked->email_otp_hash = null;
            $locked->email_otp_expires_at = null;
            $locked->email_otp_attempts = 0;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($locked, $locked, 'staff.otp_verified', null, ['email_verified_at' => $locked->email_verified_at]);

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
