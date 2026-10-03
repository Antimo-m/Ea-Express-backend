<?php

namespace App\Support;

use App\Models\User;
use App\StaffAuthenticationState;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffAuthentication
{
    public function requiresVerification(User $user): bool
    {
        return $user->role === UserRole::Rider && $user->email_verified_at === null;
    }

    public function binding(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
    }

    private function credentials(User $user): string
    {
        return hash_hmac('sha256', implode('|', [$user->id, $user->password, $user->email, $user->role->value, (int) $user->is_active]), (string) config('app.key'));
    }

    public function state(Request $request, ?User $user): StaffAuthenticationState
    {
        if (! $user?->isStaff() || ! $user->is_active || ! $request->hasSession()) {
            return StaffAuthenticationState::Guest;
        }
        $proof = $request->session()->get('staff_authentication');
        if (! is_array($proof)
            || ($proof['user_id'] ?? null) !== $user->id
            || ! is_string($proof['binding'] ?? null)
            || ! hash_equals($this->binding($request), $proof['binding'])
            || ! is_string($proof['credentials'] ?? null)
            || ! hash_equals($this->credentials($user), $proof['credentials'])) {
            return StaffAuthenticationState::Guest;
        }
        $state = is_string($proof['state'] ?? null) ? (StaffAuthenticationState::tryFrom($proof['state']) ?? StaffAuthenticationState::Guest) : StaffAuthenticationState::Guest;
        if ($state === StaffAuthenticationState::FullyAuthenticated && $this->requiresVerification($user)) {
            return StaffAuthenticationState::Guest;
        }
        if ($state !== StaffAuthenticationState::FullyAuthenticated && ($proof['started_at'] ?? 0) <= now()->subMinutes(30)->timestamp) {
            return StaffAuthenticationState::Guest;
        }

        return $state;
    }

    public function begin(Request $request, User $user, bool $remember = false): void
    {
        $request->session()->put('staff_authentication', [
            'state' => $this->requiresVerification($user) ? StaffAuthenticationState::PrimaryAuthenticated->value : StaffAuthenticationState::FullyAuthenticated->value,
            'user_id' => $user->id,
            'binding' => $this->binding($request),
            'credentials' => $this->credentials($user),
            'started_at' => now()->timestamp,
            'factor_verified_at' => null,
            'remember' => $remember,
        ]);
    }

    public function assertComplete(User $user): void
    {
        if ($user->isStaff()) {
            $request = request();
            abort_unless(Auth::guard('web')->id() === $user->id && $this->state($request, $user) === StaffAuthenticationState::FullyAuthenticated, 403, 'Completa prima la verifica di accesso.');
        }
    }

    public function factorVerified(Request $request, User $user): void
    {
        abort_unless($this->state($request, $user) === StaffAuthenticationState::PrimaryAuthenticated, 403);
        $request->session()->put('staff_authentication.state', StaffAuthenticationState::FactorVerified->value);
        $request->session()->put('staff_authentication.factor_verified_at', now()->timestamp);
    }

    public function complete(Request $request, User $user): void
    {
        abort_unless($this->state($request, $user) === StaffAuthenticationState::FactorVerified && ! $this->requiresVerification($user), 403);
        $remember = $request->session()->get('staff_authentication.remember', false);
        if ($remember) {
            Auth::guard('web')->login($user, true);
        }
        $request->session()->regenerate(true);
        $request->session()->put('staff_authentication.state', StaffAuthenticationState::FullyAuthenticated->value);
        $request->session()->put('staff_authentication.binding', $this->binding($request));
        $request->session()->put('staff_authentication.credentials', $this->credentials($user));
        $request->session()->put('password_hash_web', $user->password);
        SecurityEvent::record('authentication_completed', $user->id);
    }

    public function invalidateChallenge(Request $request): void
    {
        $challenge = $request->session()->pull('email_otp_challenge');
        $user = Auth::guard('web')->user();
        if (is_array($challenge) && $user && ($challenge['user_id'] ?? null) === $user->id) {
            $hash = $user->fresh()?->email_otp_hash;
            if ($hash && is_string($challenge['fingerprint'] ?? null) && hash_equals(hash('sha256', $hash), $challenge['fingerprint'])) {
                User::whereKey($user->id)->where('email_otp_hash', $hash)->update(['email_otp_hash' => null, 'email_otp_expires_at' => null]);
            }
        }
    }
}
