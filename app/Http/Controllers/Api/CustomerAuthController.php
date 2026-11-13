<?php

namespace App\Http\Controllers\Api;

use App\Actions\SendEmailOtp;
use App\Actions\VerifyEmailOtp;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AllowedPortalText;
use App\Rules\SafePasswordLength;
use App\Support\AuthProtection;
use App\Support\AuthSecurityStore;
use App\Support\BetaTerms;
use App\Support\CustomerAuthentication;
use App\Support\CustomerIdentity;
use App\Support\PasswordPolicy;
use App\Support\PasswordRecovery;
use App\Support\PortalTextPolicy;
use App\Support\SecurityEvent;
use App\Support\TrustedRiderDevices;
use App\Support\UnconfirmedCustomers;
use App\UserRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function csrf(): JsonResponse
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function platform(): JsonResponse
    {
        return response()->json(BetaTerms::platform());
    }

    public function login(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => AuthSecurityStore::email($request->input('email'))]);
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', new SafePasswordLength], 'remember' => ['sometimes', 'boolean']]);
        $remember = $data['remember'] ?? false;
        app(AuthProtection::class)->attempt($request, 'customer', ['email' => $data['email'], 'password' => $data['password'], 'role' => UserRole::Customer->value, 'is_active' => true]);
        $user = $request->user('customer');
        if (app(TrustedRiderDevices::class)->consume($request, $user)) {
            return $this->completeLogin($request, $user, $remember);
        }

        Auth::guard('customer')->logoutCurrentDevice();
        $request->session()->forget('account_authentication.customer');
        $request->session()->regenerate(true);
        $authentication = app(CustomerAuthentication::class);
        $authentication->begin($request, $user, $remember);
        $message = 'Ti abbiamo inviato via email un codice di verifica per confermare l’accesso da questo dispositivo.';
        $retryAfter = null;
        try {
            app(SendEmailOtp::class)->handle($user, SendEmailOtp::CustomerLogin);
        } catch (ValidationException $exception) {
            $message = $exception->getMessage();
        } catch (ThrottleRequestsException $exception) {
            $retryAfter = max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60));
            $message = 'Un codice è stato richiesto da poco. Tra '.$retryAfter.' secondi potrai richiederne uno nuovo.';
        }

        return response()->json(['otp_required' => true, 'email' => CustomerAuthentication::maskedEmail($user->email), 'message' => $message, 'retry_after' => $retryAfter, 'csrf_token' => csrf_token()], 202);
    }

    public function otp(Request $request): JsonResponse
    {
        $user = app(CustomerAuthentication::class)->pending($request);

        return response()->json(['pending' => $user !== null, 'email' => $user ? CustomerAuthentication::maskedEmail($user->email) : null]);
    }

    public function sendOtp(Request $request, SendEmailOtp $send): JsonResponse
    {
        $send->handle($this->pendingCustomer($request), SendEmailOtp::CustomerLogin);

        return response()->json(['message' => 'Nuovo codice inviato via email. È valido per '.(int) ceil(config('auth_security.otp.ttl_seconds') / 60).' minuti.']);
    }

    public function verifyOtp(Request $request, VerifyEmailOtp $verify): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);
        $user = $this->pendingCustomer($request);
        $verify->handle($user, $data['code'], SendEmailOtp::CustomerLogin);

        return $this->completeLogin($request, $user->refresh(), app(CustomerAuthentication::class)->remember($request));
    }

    public function forgotPassword(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $recovery->request($request, $data['email'], UserRole::Customer);

        return response()->json(['message' => PasswordRecovery::RequestedMessage], 202);
    }

    public function resetPassword(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'token' => ['required', 'string', 'max:128'], 'password' => [...PasswordPolicy::rules(), 'confirmed'], 'password_confirmation' => ['required', 'string', new SafePasswordLength]]);
        $recovery->reset($request, $data, UserRole::Customer);

        return response()->json(['message' => 'Password reimpostata. Accedi con la nuova password: su questo dispositivo ti chiederemo anche il codice di verifica.']);
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge(PortalTextPolicy::normalizeFields($request->only(['name', 'business_type', 'business_description'])));
        $rules = CustomerIdentity::rules(required: true);
        $rules['business_type'][] = new AllowedPortalText;
        $rules['business_description'][] = new AllowedPortalText;
        $data = $request->validate([...$rules, 'name' => ['required', 'string', 'max:150', Rule::when($request->input('sender_type') !== 'private', [new AllowedPortalText])], 'email' => ['required', 'email', 'lowercase', 'max:255'], 'password' => [...PasswordPolicy::rules(), 'confirmed'],
            'privacy_acknowledged' => ['accepted'], 'beta_terms_accepted' => Rule::when((bool) config('beta.enabled'), ['accepted'], ['nullable'])], [
                'privacy_acknowledged.accepted' => 'Conferma di aver letto l’informativa privacy.', 'beta_terms_accepted.accepted' => 'Accetta le condizioni d’uso della beta per continuare.',
            ]);
        $attributes = CustomerIdentity::normalize(Arr::only($data, ['name', 'password', 'sender_type', 'business_type', 'business_description']));
        $attributes['password'] = Hash::make($data['password']);
        $attributes += ['privacy_acknowledged_at' => now(), 'privacy_version' => config('customer_privacy.version'),
            'terms_accepted_at' => config('beta.enabled') ? now() : null, 'terms_version' => config('beta.enabled') ? config('beta.terms_version') : null];
        try {
            DB::transaction(function () use ($data, $attributes): void {
                $existing = User::query()->where('email', $data['email'])->lockForUpdate()->first();
                if (! $existing) {
                    User::unguarded(fn () => User::create([...$attributes, 'email' => $data['email'], 'role' => UserRole::Customer, 'is_active' => true]));

                    return;
                }
                if ($existing->is_active && UnconfirmedCustomers::includes($existing)) {
                    $existing->forceFill($attributes)->saveRevokingSessions();
                    SecurityEvent::record('unconfirmed_customer_registered_again', $existing->id);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            SecurityEvent::record('concurrent_customer_registration');
        }

        return response()->json(['message' => 'Se i dati sono validi, puoi accedere con le tue credenziali.'], 202);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('customer');

        return response()->json(['user' => ['slug' => $user->slug, 'name' => $user->name, 'email' => $user->email, 'sender_type' => $user->sender_type, 'business_type' => $user->business_type, 'business_description' => $user->business_description, 'notify_orders' => $user->notify_orders, 'notify_messages' => $user->notify_messages, 'portal_preferences' => $user->portal_preferences ?? []], 'csrf_token' => csrf_token()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('customer')->logout();
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Disconnessione effettuata.', 'csrf_token' => csrf_token()]);
    }

    private function pendingCustomer(Request $request): User
    {
        return app(CustomerAuthentication::class)->pending($request)
            ?? throw ValidationException::withMessages(['code' => 'La verifica è scaduta. Accedi di nuovo per ricevere un nuovo codice.']);
    }

    private function completeLogin(Request $request, User $user, bool $remember): JsonResponse
    {
        $guard = Auth::guard('customer');
        $guard->setRememberDuration((int) config('customer_privacy.remember_minutes'));
        $guard->login($user, $remember);
        try {
            app(AuthProtection::class)->completed($request, $user);
        } catch (\Throwable $exception) {
            $guard->logout();
            throw $exception;
        }
        app(CustomerAuthentication::class)->forget($request);
        $request->session()->regenerate();
        $request->session()->put('password_hash_customer', $user->password);
        app(TrustedRiderDevices::class)->issue($request, $user);

        return $this->me($request);
    }
}
