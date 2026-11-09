<?php

namespace App\Support;

use App\Jobs\SendAuthSecurityAlert;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class AuthSecurityMonitor
{
    public function __construct(private AuthSecurityStore $store) {}

    public function activity(string $event, string $subject = 'application'): void
    {
        $key = AuthSecurityStore::key('global:'.$event, $subject);
        $window = $event === 'login' || $event === 'failures' || $event === 'otp_requests'
            ? config('auth_security.monitor.global_window_seconds') : config('auth_security.monitor.window_seconds');
        $count = $this->store->available(fn () => $this->store->limiter()->hit($key, $window));
        $threshold = config('auth_security.monitor.'.match ($event) {
            'login' => 'global_attempts', 'failures' => 'failures', 'otp_requests' => 'otp_requests', 'missing_accounts' => 'missing_accounts', default => 'rate_limit_hits',
        });
        if ($count >= $threshold) {
            $this->alert($event, $subject);
        }
    }

    public function pattern(string $type, string $subject, string $member, int $threshold): bool
    {
        $key = AuthSecurityStore::key('pattern:'.$type, $subject);

        return $this->store->atomic([$key], function () use ($key, $member, $threshold, $type, $subject): bool {
            $members = $this->store->available(fn () => $this->store->cache()->get($key, []));
            $members = array_filter($members, fn (int $at): bool => $at > now()->timestamp - config('auth_security.monitor.window_seconds'));
            if (count($members) < $threshold) {
                $members[AuthSecurityStore::key('member', $member)] = now()->timestamp;
            }
            $this->store->available(fn () => $this->store->cache()->put($key, $members, config('auth_security.monitor.window_seconds')));
            if (count($members) >= $threshold) {
                $this->alert($type, $subject);

                return true;
            }

            return false;
        });
    }

    public function alert(string $reason, string $subject): void
    {
        $key = AuthSecurityStore::key('alert:'.$reason, $subject);
        if (! $this->store->available(fn () => $this->store->cache()->add($key, true, config('auth_security.monitor.cooldown_seconds')))) {
            return;
        }
        SecurityEvent::record('anomaly_'.$reason);
        if (! $this->store->available(fn () => $this->store->cache()->add('auth:alert:delivery', true, config('auth_security.monitor.delivery_cooldown_seconds')))) {
            return;
        }
        try {
            if (config('queue.connections.'.config('auth_security.monitor.queue_connection').'.driver') === 'sync') {
                SecurityEvent::record('alert_queue_misconfigured');

                return;
            }
            SendAuthSecurityAlert::dispatch($reason, SecurityEvent::requestId())
                ->onConnection(config('auth_security.monitor.queue_connection'))->onQueue(config('auth_security.monitor.queue'));
        } catch (Throwable) {
            SecurityEvent::record('alert_queue_unavailable');
        }
    }

    public function authenticated(Request $request, User $user): void
    {
        $key = AuthSecurityStore::key('context', $user->email);
        $packed = inet_pton((string) $request->ip());
        $network = $packed === false ? 'unknown' : substr($packed, 0, strlen($packed) === 4 ? 3 : 7);
        $context = AuthSecurityStore::key('network', $network);
        $previous = $this->store->available(fn () => $this->store->cache()->get($key));
        if (is_string($previous) && ! hash_equals($previous, $context)) {
            SecurityEvent::record('access_context_changed', $user->id);
            $this->pattern('access_context_changed', $user->email, $previous, config('auth_security.monitor.distinct_networks'));
            $this->pattern('access_context_changed', $user->email, $context, config('auth_security.monitor.distinct_networks'));
        }
        $this->store->available(fn () => $this->store->cache()->put($key, $context, config('auth_security.monitor.context_seconds')));
        SecurityEvent::record('authentication_completed', $user->id);
    }
}
