<?php

namespace App\Actions;

use App\Models\User;
use App\Support\PhoneVerification;
use App\Support\SmsGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SendPhoneOtp
{
    public function __construct(private SmsGateway $sms) {}

    public function handle(User $user, string $phone): void
    {
        abort_unless(PhoneVerification::enabled(), 404);
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $hash = Hash::make(self::digest($user->id, $phone, $code));
        DB::transaction(function () use ($user, $phone, &$hash, &$code) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->isStaff(), 403);
            if ($locked->phone_verified_at) {
                throw ValidationException::withMessages(['phone' => 'Il numero è già verificato.']);
            }
            if ($locked->phone_otp_sent_at?->greaterThan(now()->subMinute())) {
                throw ValidationException::withMessages(['phone' => 'Attendi almeno un minuto prima di richiedere un nuovo codice.']);
            }
            if (! $locked->phone_otp_window_at || $locked->phone_otp_window_at->lessThanOrEqualTo(now()->subHour())) {
                $locked->phone_otp_window_at = now();
                $locked->phone_otp_send_count = 0;
            }
            if ($locked->phone_otp_send_count >= 3) {
                throw ValidationException::withMessages(['phone' => 'Hai raggiunto il limite di tre invii in un’ora. Riprova più tardi.']);
            }
            if (User::where('phone', $phone)->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['phone' => 'Numero non disponibile. Contatta l’amministratore.']);
            }
            $key = 'otp-phone:'.hash('sha256', $phone);
            if (RateLimiter::tooManyAttempts($key, 3)) {
                throw ValidationException::withMessages(['phone' => 'Troppi invii a questo numero. Riprova tra un’ora.']);
            }
            RateLimiter::hit($key, 3600);
            while ($locked->phone_otp_hash && $locked->pending_phone && Hash::check(self::digest($user->id, $locked->pending_phone, $code), $locked->phone_otp_hash)) {
                $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $hash = Hash::make(self::digest($user->id, $phone, $code));
            }
            $locked->pending_phone = $phone;
            $locked->phone_otp_hash = $hash;
            $locked->phone_otp_expires_at = now()->addMinutes(15);
            $locked->phone_otp_sent_at = now();
            $locked->phone_otp_attempts = 0;
            $locked->phone_otp_send_count++;
            $locked->save();
        }, 3);
        try {
            $this->sms->sendOtp($phone, $code);
        } catch (RuntimeException $exception) {
            User::whereKey($user->id)->where('phone_otp_hash', $hash)->update(['phone_otp_hash' => null, 'phone_otp_expires_at' => null]);
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }
    }

    public static function digest(int $userId, string $phone, string $code): string
    {
        return hash_hmac('sha256', $userId.'|'.$phone.'|'.$code, (string) config('app.key'));
    }
}
