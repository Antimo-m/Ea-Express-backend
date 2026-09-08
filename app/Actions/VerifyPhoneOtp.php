<?php

namespace App\Actions;

use App\Models\User;
use App\Support\PhoneVerification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyPhoneOtp
{
    public function handle(User $user, string $code): void
    {
        abort_unless(PhoneVerification::enabled(), 404);
        try {
            $error = DB::transaction(function () use ($user, $code): ?string {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($locked->is_active && $locked->isStaff(), 403);
                if ($locked->phone_verified_at || ! $locked->phone_otp_hash || ! $locked->pending_phone) {
                    return 'Richiedi un nuovo codice di verifica.';
                }
                if (! $locked->phone_otp_expires_at || $locked->phone_otp_expires_at->lessThanOrEqualTo(now())) {
                    $locked->phone_otp_hash = null;
                    $locked->phone_otp_expires_at = null;
                    $locked->save();

                    return 'Il codice è scaduto. Richiedine uno nuovo.';
                }
                if ($locked->phone_otp_attempts >= 5) {
                    return 'Troppi codici errati. Richiedi un nuovo OTP.';
                }
                if (! Hash::check(SendPhoneOtp::digest($locked->id, $locked->pending_phone, $code), $locked->phone_otp_hash)) {
                    $locked->phone_otp_attempts++;
                    if ($locked->phone_otp_attempts >= 5) {
                        $locked->phone_otp_hash = null;
                    }
                    $locked->save();

                    return 'Codice non corretto. Dopo cinque errori è necessario richiederne uno nuovo.';
                }
                if (User::where('phone', $locked->pending_phone)->where('id', '!=', $locked->id)->exists()) {
                    return 'Numero non disponibile. Contatta l’amministratore.';
                }
                $locked->phone = $locked->pending_phone;
                $locked->phone_verified_at = now();
                $locked->pending_phone = null;
                $locked->phone_otp_hash = null;
                $locked->phone_otp_expires_at = null;
                $locked->phone_otp_attempts = 0;
                $locked->save();

                return null;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $error = 'Numero non disponibile. Contatta l’amministratore.';
        }
        if ($error !== null) {
            throw ValidationException::withMessages(['code' => $error]);
        }
    }
}
