<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthProtection
{
    public const INVALID_CREDENTIALS = 'Impossibile verificare le credenziali. Controlla i dati inseriti e riprova.';

    public function __construct(private AuthSecurityStore $store, private AuthSecurityMonitor $monitor, private BotVerification $bot) {}

    public function inspect(Request $request): void
    {
        $this->monitor->activity('login');
        $ip = (string) $request->ip();
        $key = AuthSecurityStore::key('ip', $ip);
        $count = $this->store->consume($key, config('auth_security.ip.hard_attempts'), config('auth_security.ip.window_seconds'));
        $request->attributes->set('auth_ip_limited', $count > config('auth_security.ip.attempts'));
        $email = AuthSecurityStore::email(is_string($request->input('email')) ? $request->input('email') : '');
        $ipRisk = $this->monitor->pattern('password_spraying', $ip, $email, config('auth_security.monitor.distinct_accounts'));
        $accountRisk = $this->monitor->pattern('distributed_account_attack', $email, $ip, config('auth_security.monitor.distinct_ips'));
        $request->attributes->set('auth_risky', $ipRisk || $accountRisk);
    }

    /** @param array<string, mixed> $credentials */
    public function attempt(Request $request, string $guard, #[\SensitiveParameter] array $credentials, bool $remember = false): void
    {
        $email = AuthSecurityStore::email($credentials['email']);
        $credentials['email'] = $email;
        $key = AuthSecurityStore::key('account', $email);
        $this->store->atomic([$key], function () use ($request, $guard, $credentials, $remember, $key, $email): void {
            $state = $this->store->available(fn () => $this->store->cache()->get($key, []));
            $now = now()->timestamp;
            $suspendedUntil = $state['suspended_until'] ?? 0;
            $nextAttemptAt = max($state['next_attempt_at'] ?? 0, $suspendedUntil);
            $trustedDevice = $guard === 'web' && app(StaffDeviceProof::class)->allows($request, $email);
            $deviceBypass = $trustedDevice && $nextAttemptAt > $now;
            $request->attributes->set('auth_device_recovery', $deviceBypass || $suspendedUntil > 0);
            if ($deviceBypass) {
                $this->store->consume(AuthSecurityStore::key('device:attempt', $request->cookie(config('auth_security.device.cookie'))), config('auth_security.device.attempts'), config('auth_security.device.window_seconds'));
                SecurityEvent::record('targeted_account_recovery_attempt');
            }
            if ($nextAttemptAt > $now && ! $trustedDevice) {
                $this->monitor->activity('rate_limit_hits', $email);
                $this->store->limited($nextAttemptAt - $now);
            }
            if ($suspendedUntil > 0) {
                $state = [];
            }
            $failures = array_values(array_filter($state['failures'] ?? [], fn (int $at): bool => $at > $now - config('auth_security.account.window_seconds')));
            $recent = count(array_filter($failures, fn (int $at): bool => $at > $now - config('auth_security.account.short_window_seconds')));
            $ipLimited = $request->attributes->get('auth_ip_limited', false);
            $verifiedBot = $this->bot->verify($request, ! $trustedDevice && ($ipLimited || $recent >= config('auth_security.account.short_max_failures') || $request->attributes->get('auth_risky', false)));
            if ($ipLimited && ! $verifiedBot && ! $trustedDevice) {
                $this->monitor->activity('rate_limit_hits', (string) $request->ip());
                $this->store->limited($this->store->available(fn () => $this->store->limiter()->availableIn(AuthSecurityStore::key('ip', (string) $request->ip()))));
            }
            if (! Auth::guard($guard)->attempt($credentials, $remember)) {
                if (Auth::guard($guard)->getLastAttempted() === null) {
                    $this->monitor->activity('missing_accounts', (string) $request->ip());
                }
                $request->attributes->set('auth_device_recovery', $deviceBypass || $suspendedUntil > 0);
                if ($deviceBypass) {
                    $this->monitor->activity('failures');
                    throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS]);
                }
                $failures[] = $now;
                $count = count($failures);
                $delay = 0;
                foreach (config('auth_security.account.delays') as $threshold => $seconds) {
                    if ($count >= $threshold) {
                        $delay = $seconds;
                    }
                }
                $suspension = $count >= config('auth_security.account.max_failures') ? $now + config('auth_security.account.suspension_seconds') : 0;
                $this->store->available(fn () => $this->store->cache()->put($key, [
                    'failures' => $failures, 'next_attempt_at' => $now + $delay, 'suspended_until' => $suspension,
                ], max(config('auth_security.account.window_seconds'), config('auth_security.account.suspension_seconds'), $delay)));
                $this->monitor->activity('failures');
                if ($count >= config('auth_security.account.short_max_failures')) {
                    $this->monitor->alert('account_targeted', $email);
                }
                if ($suspension) {
                    SecurityEvent::record('account_temporarily_suspended');
                } elseif ($delay) {
                    SecurityEvent::record('progressive_delay_activated');
                }
                throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS]);
            }
            SecurityEvent::record('primary_authenticated', Auth::guard($guard)->id());
        });
    }

    public function completed(Request $request, User $user): void
    {
        $key = AuthSecurityStore::key('account', AuthSecurityStore::email($user->email));
        $this->store->atomic([$key], fn () => $this->store->available(fn () => $this->store->cache()->forget($key)));
        $request->session()->forget('auth_bot_required');
        $this->monitor->authenticated($request, $user);
    }
}
