<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\Support\SecurityEvent;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendEmailOtp
{
    public function handle(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $recipient = DB::transaction(function () use ($user, &$code): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->role === UserRole::Rider, 403);
            if ($locked->email_verified_at) {
                throw ValidationException::withMessages(['code' => 'L’email è già verificata.']);
            }
            if ($locked->email_otp_sent_at?->greaterThan(now()->subMinute())) {
                throw ValidationException::withMessages(['code' => 'Attendi un minuto prima di richiedere un nuovo codice.']);
            }
            if (! $locked->email_otp_window_at || $locked->email_otp_window_at->lessThanOrEqualTo(now()->subHour())) {
                $locked->email_otp_window_at = now();
                $locked->email_otp_send_count = 0;
            }
            if ($locked->email_otp_send_count >= 3) {
                throw ValidationException::withMessages(['code' => 'Limite di tre invii in un’ora raggiunto. Riprova più tardi.']);
            }
            while ($locked->email_otp_hash && Hash::check(self::digest($locked, $code), $locked->email_otp_hash)) {
                $code = (string) random_int(100000, 999999);
            }
            $locked->email_otp_hash = Hash::make(self::digest($locked, $code));
            $locked->email_otp_expires_at = now()->addMinutes(15);
            $locked->email_otp_sent_at = now();
            $locked->email_otp_attempts = 0;
            $locked->email_otp_send_count++;
            $locked->save();

            return $locked;
        }, 3);
        $hash = $recipient->email_otp_hash;
        try {
            $recipient->notify(new RiderEmailOtp($code));
            session()->put('email_otp_challenge', [
                'user_id' => $recipient->id,
                'fingerprint' => hash('sha256', $hash),
                'challenge_id' => (string) Str::uuid(),
                'type' => 'email_verification',
                'session_binding' => hash_hmac('sha256', session()->getId(), (string) config('app.key')),
                'issued_at' => now()->timestamp,
                'expires_at' => $recipient->email_otp_expires_at->timestamp,
            ]);
            SecurityEvent::record('otp_challenge_created', $recipient->id);
            SecurityEvent::record('otp_sent', $recipient->id);
        } catch (Throwable $exception) {
            report($exception);
            User::whereKey($user->id)->where('email_otp_hash', $hash)->update(['email_otp_hash' => null, 'email_otp_expires_at' => null]);
            throw ValidationException::withMessages(['code' => 'Invio email non riuscito. Attendi un minuto e riprova.']);
        }
    }

    public static function digest(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->email.'|'.$code, (string) config('app.key'));
    }
}
