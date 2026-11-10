<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AccountSessionRevocation
{
    public function revoke(User $user): void
    {
        $user->getConnection()->transaction(function () use ($user): void {
            $query = $user->getConnection()->table($user->getTable())->where('id', $user->id);
            $current = (clone $query)->lockForUpdate()->first(['auth_version', 'email']);
            abort_unless($current, 401);
            $token = Str::random(60);
            (clone $query)->update([
                'auth_version' => DB::raw('auth_version + 1'), 'remember_token' => $token,
                'email_otp_hash' => null, 'email_otp_expires_at' => null,
                'phone_otp_hash' => null, 'phone_otp_expires_at' => null,
            ]);
            $user->auth_version = (int) $current->auth_version + 1;
            $user->syncOriginalAttribute('auth_version');
            $user->remember_token = $token;
            $user->email_otp_hash = null;
            $user->email_otp_expires_at = null;
            $user->phone_otp_hash = null;
            $user->phone_otp_expires_at = null;
            $previous = clone $user;
            $previous->email = $current->email;
            Password::broker('users')->deleteToken($previous);
            Password::broker('users')->deleteToken($user);
            app(TrustedRiderDevices::class)->revoke($user);
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
            }
            SecurityEvent::record('account_sessions_revoked', $user->id);
        }, 3);
    }

    public function authorize(Request $request, User $user, string $guard): void
    {
        $request->session()->put('account_authentication.'.$guard, ['user_id' => $user->id, 'version' => (int) $user->auth_version]);
    }

    public function matches(Request $request, User $user, string $guard): bool
    {
        $proof = $request->session()->get('account_authentication.'.$guard);

        return is_array($proof) && ($proof['user_id'] ?? null) === $user->id
            && is_int($proof['version'] ?? null) && $proof['version'] >= 0
            && $proof['version'] === (int) $user->auth_version;
    }

    public function assertCurrent(Request $request, User $user, string $guard): void
    {
        abort_unless(Auth::guard($guard)->id() === $user->id && $this->matches($request, $user, $guard), $guard === 'customer' ? 401 : 403, 'Accedi nuovamente al tuo account.');
    }

    public function renewCurrent(Request $request, User $user, string $guard): void
    {
        $request->session()->forget(['email_otp_challenge', 'auth.password_confirmed_at']);
        $request->session()->regenerate(true);
        $this->authorize($request, $user, $guard);
        $request->session()->put('password_hash_'.$guard, $user->password);
        if ($guard === 'web') {
            $authentication = app(StaffAuthentication::class);
            $request->session()->put('staff_authentication.binding', $authentication->binding($request));
            $request->session()->put('staff_authentication.credentials', $authentication->credentialProof($user));
        }
    }
}
