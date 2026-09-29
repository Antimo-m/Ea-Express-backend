<?php

namespace App\Actions;

use App\Models\User;
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
            throw ValidationException::withMessages(['code' => $error]);
        }
    }
}
