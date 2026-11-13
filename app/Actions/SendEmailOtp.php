<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\Support\AuthSecurityMonitor;
use App\Support\AuthSecurityStore;
use App\Support\SecurityEvent;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendEmailOtp
{
    public const StaffVerification = 'email_verification';

    public const CustomerLogin = 'customer_login';

    public function handle(User $user, string $purpose = self::StaffVerification): void
    {
        app(AuthSecurityStore::class)->consume(AuthSecurityStore::key('otp:send:ip', (string) request()->ip()), config('auth_security.otp.ip_sends'), config('auth_security.otp.window_seconds'));
        app(AuthSecurityMonitor::class)->activity('otp_requests');
        if (! $this->safeMailer((string) config('auth_security.otp.mailer'))) {
            SecurityEvent::record('otp_mailer_misconfigured', $user->id);
            throw ValidationException::withMessages(['code' => 'Invio del codice temporaneamente non disponibile. Contatta l’amministratore.']);
        }
        $code = (string) random_int(100000, 999999);
        $recipient = DB::transaction(function () use ($user, &$code, $purpose): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless(self::eligible($locked, $purpose), 403);
            $window = config('auth_security.otp.window_seconds');
            if (! $locked->email_otp_window_at || $locked->email_otp_window_at->lessThanOrEqualTo(now()->subSeconds($window))) {
                $locked->email_otp_window_at = now();
                $locked->email_otp_send_count = 0;
                $locked->email_otp_attempts = 0;
            }
            $retry = max(1, $locked->email_otp_window_at->timestamp + $window - now()->timestamp);
            if ($locked->email_otp_attempts >= config('auth_security.otp.attempts')) {
                SecurityEvent::record('otp_procedure_exhausted', $locked->id);
                app(AuthSecurityStore::class)->limited($retry);
            }
            if ($locked->email_otp_sent_at?->greaterThan(now()->subSeconds(config('auth_security.otp.resend_seconds')))) {
                SecurityEvent::record('otp_resend_limited', $locked->id);
                app(AuthSecurityStore::class)->limited($locked->email_otp_sent_at->timestamp + config('auth_security.otp.resend_seconds') - now()->timestamp);
            }
            if ($locked->email_otp_send_count >= config('auth_security.otp.resends') + 1) {
                SecurityEvent::record('otp_resend_limited', $locked->id);
                app(AuthSecurityStore::class)->limited($retry);
            }
            while ($locked->email_otp_hash && Hash::check(self::digest($locked, $code), $locked->email_otp_hash)) {
                $code = (string) random_int(100000, 999999);
            }
            $locked->email_otp_hash = Hash::make(self::digest($locked, $code));
            $locked->email_otp_expires_at = now()->addSeconds(config('auth_security.otp.ttl_seconds'));
            $locked->email_otp_sent_at = now();
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
                'type' => $purpose,
                'session_binding' => hash_hmac('sha256', session()->getId(), (string) config('app.key')),
                'issued_at' => now()->timestamp,
                'expires_at' => $recipient->email_otp_expires_at->timestamp,
            ]);
            SecurityEvent::record('otp_challenge_created', $recipient->id);
            SecurityEvent::record('otp_sent', $recipient->id);
        } catch (Throwable) {
            SecurityEvent::record('otp_delivery_failed', $recipient->id);
            User::whereKey($user->id)->where('email_otp_hash', $hash)->update(['email_otp_hash' => null, 'email_otp_expires_at' => null]);
            throw ValidationException::withMessages(['code' => 'Invio email non riuscito. Attendi un minuto e riprova.']);
        }
    }

    /** @param list<string> $visited */
    private function safeMailer(string $name, array $visited = []): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }
        $mailer = config('mail.mailers.'.$name, []);
        $transport = $mailer['transport'] ?? null;
        if ($transport === null || $transport === 'log' || ($transport === 'array' && ! app()->environment('testing'))) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ($mailer['mailers'] ?? [] as $child) {
                if (! $this->safeMailer($child, [...$visited, $name])) {
                    return false;
                }
            }

            return count($mailer['mailers'] ?? []) > 0;
        }

        return true;
    }

    public static function eligible(User $user, string $purpose): bool
    {
        return $user->is_active && match ($purpose) {
            self::StaffVerification => $user->isStaff(),
            self::CustomerLogin => $user->role === UserRole::Customer,
            default => false,
        };
    }

    public static function digest(User $user, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->email.'|'.$code, (string) config('app.key'));
    }
}
