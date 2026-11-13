<?php

namespace App\Support;

use App\Actions\RecordEconomicAudit;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\PasswordResetLink;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password recovery for customers and riders. Admin accounts are never eligible:
 * their password is managed exclusively from the server console (app:set-password).
 */
class PasswordRecovery
{
    public const RequestedMessage = 'Se l’indirizzo è associato a un account, riceverai un’email con il link per reimpostare la password. Il link vale 15 minuti.';

    public const InvalidLinkMessage = 'Il link non è valido o è scaduto. Richiedi un nuovo link di ripristino.';

    public function __construct(private AuthSecurityStore $store) {}

    public function request(Request $request, string $email, UserRole $audience): void
    {
        abort_if($audience === UserRole::Admin, 403);
        $email = AuthSecurityStore::email($email);
        $this->throttleRequest($request, $email);
        $sent = false;
        Password::broker('users')->sendResetLink(['email' => $email, 'role' => $audience->value, 'is_active' => true], function (User $user, string $token) use (&$sent): void {
            $user->notify(new PasswordResetLink($token));
            $sent = true;
        });
        if ($sent) {
            SecurityEvent::record('password_reset_requested', User::query()->where('email', $email)->value('id'));

            return;
        }
        Hash::make(Str::random(40));
        $admin = User::query()->where('email', $email)->where('role', UserRole::Admin)->value('id');
        if ($admin) {
            SecurityEvent::record('admin_password_reset_blocked', $admin);
            app(AuthSecurityMonitor::class)->alert('admin_password_reset_attempt', $email);

            return;
        }
        SecurityEvent::record('password_reset_request_ignored');
    }

    /** @param array{email: string, token: string, password: string, password_confirmation: string} $data */
    public function reset(Request $request, array $data, UserRole $audience): void
    {
        abort_if($audience === UserRole::Admin, 403);
        $email = AuthSecurityStore::email($data['email']);
        $this->throttleSubmission($request, $email);
        $resetUser = null;
        $status = Password::broker('users')->reset(
            ['email' => $email, 'role' => $audience->value, 'is_active' => true, 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation']],
            function (User $user, string $password) use (&$resetUser, $data): void {
                DB::transaction(function () use ($user, $password, $data, &$resetUser): void {
                    $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                    abort_if($locked->role === UserRole::Admin || ! $locked->is_active, 403);
                    PasswordTokenClaim::claim($locked, $data['token'], 'users', self::InvalidLinkMessage);
                    $locked->password = Hash::make($password);
                    $locked->email_verified_at ??= now();
                    $locked->saveRevokingSessions();
                    app(RecordEconomicAudit::class)->handle(null, $locked, 'user.password-reset', null, ['role' => $locked->role->value]);
                    $resetUser = $locked;
                }, 3);
            },
        );
        if ($status !== Password::PASSWORD_RESET || ! $resetUser instanceof User) {
            SecurityEvent::record('password_reset_rejected');
            throw ValidationException::withMessages(['email' => self::InvalidLinkMessage]);
        }
        $resetUser->notify(new PasswordChanged);
        SecurityEvent::record('password_reset_completed', $resetUser->id);
    }

    /**
     * Limits attempts to use a reset or activation token, per network and per account.
     */
    public function throttleSubmission(Request $request, string $email): void
    {
        $window = (int) config('auth_security.password_reset.submit_window_seconds');
        $this->store->consume(AuthSecurityStore::key('password-reset:submit:ip', (string) $request->ip()), (int) config('auth_security.password_reset.submit_ip_attempts'), $window);
        $this->store->consume(AuthSecurityStore::key('password-reset:submit:account', AuthSecurityStore::email($email)), (int) config('auth_security.password_reset.submit_account_attempts'), $window);
    }

    /**
     * Progressive limit per address: a pause after the first and second request, then a block until the window ends.
     */
    private function throttleRequest(Request $request, string $email): void
    {
        $this->store->consume(AuthSecurityStore::key('password-reset:ip', (string) $request->ip()), (int) config('auth_security.password_reset.ip_requests'), (int) config('auth_security.password_reset.ip_window_seconds'));
        $key = AuthSecurityStore::key('password-reset:account', $email);
        $this->store->atomic([$key], function () use ($key): void {
            $now = now()->timestamp;
            $window = (int) config('auth_security.password_reset.account_window_seconds');
            $requests = array_values(array_filter((array) $this->store->available(fn () => $this->store->cache()->get($key, [])), fn (mixed $at): bool => is_int($at) && $at > $now - $window));
            $count = count($requests);
            if ($count >= (int) config('auth_security.password_reset.account_requests')) {
                SecurityEvent::record('password_reset_blocked');
                $this->store->limited($requests[0] + $window - $now);
            }
            $delay = (int) (config('auth_security.password_reset.delays')[$count] ?? 0);
            if ($count > 0 && $now - end($requests) < $delay) {
                $this->store->limited($delay - ($now - end($requests)));
            }
            $requests[] = $now;
            $this->store->available(fn () => $this->store->cache()->put($key, $requests, $window));
        });
    }
}
