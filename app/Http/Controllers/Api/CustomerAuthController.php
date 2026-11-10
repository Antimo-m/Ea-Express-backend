<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AllowedPortalText;
use App\Rules\SafePasswordLength;
use App\Support\AuthProtection;
use App\Support\AuthSecurityStore;
use App\Support\CustomerIdentity;
use App\Support\PasswordPolicy;
use App\Support\PortalTextPolicy;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CustomerAuthController extends Controller
{
    public function csrf(): JsonResponse
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function login(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => AuthSecurityStore::email($request->input('email'))]);
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', new SafePasswordLength], 'remember' => ['sometimes', 'boolean']]);
        Auth::guard('customer')->setRememberDuration((int) config('customer_privacy.remember_minutes'));
        app(AuthProtection::class)->attempt($request, 'customer', ['email' => $data['email'], 'password' => $data['password'], 'role' => UserRole::Customer->value, 'is_active' => true], $data['remember'] ?? false);
        try {
            app(AuthProtection::class)->completed($request, $request->user('customer'));
        } catch (\Throwable $exception) {
            Auth::guard('customer')->logout();
            throw $exception;
        }
        $request->session()->regenerate();
        $request->session()->put('password_hash_customer', $request->user('customer')->password);

        return $this->me($request);
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge(PortalTextPolicy::normalizeFields($request->only(['name', 'business_type', 'business_description'])));
        $rules = CustomerIdentity::rules(required: true);
        $rules['business_type'][] = new AllowedPortalText;
        $rules['business_description'][] = new AllowedPortalText;
        $data = $request->validate([...$rules, 'name' => ['required', 'string', 'max:150', Rule::when($request->input('sender_type') !== 'private', [new AllowedPortalText])], 'email' => ['required', 'email', 'lowercase', 'max:255'], 'password' => [...PasswordPolicy::rules(), 'confirmed']]);
        $attributes = CustomerIdentity::normalize(Arr::only($data, ['name', 'password', 'sender_type', 'business_type', 'business_description']));
        $attributes['password'] = Hash::make($data['password']);
        User::unguarded(fn () => User::firstOrCreate(['email' => $data['email']], [
            ...$attributes, 'role' => UserRole::Customer, 'is_active' => true,
        ]));

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
}
