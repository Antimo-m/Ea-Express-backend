<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecoverAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafePasswordLength;
use App\Support\CustomerIdentity;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function csrf(): JsonResponse
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:72'], 'remember' => ['sometimes', 'boolean']]);
        if (! Auth::guard('customer')->attempt(['email' => $data['email'], 'password' => $data['password'], 'role' => UserRole::Customer->value, 'is_active' => true], $data['remember'] ?? false)) {
            throw ValidationException::withMessages(['email' => 'Email o password non corrette.']);
        }
        $request->session()->regenerate();
        $request->session()->put('password_hash_customer', $request->user('customer')->password);

        return $this->me($request);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([...CustomerIdentity::rules(), 'name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'lowercase', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Rules\Password::min(12), new SafePasswordLength]]);
        $user = new User(CustomerIdentity::normalize($data));
        $user->role = UserRole::Customer;
        $user->is_active = true;
        $user->save();
        Auth::guard('customer')->login($user->refresh());
        $request->session()->regenerate();
        $request->session()->put('password_hash_customer', $request->user('customer')->password);

        return $this->me($request)->setStatusCode(201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('customer');

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'sender_type' => $user->sender_type, 'business_type' => $user->business_type, 'business_description' => $user->business_description, 'notify_orders' => $user->notify_orders, 'notify_messages' => $user->notify_messages], 'csrf_token' => csrf_token()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Disconnessione effettuata.', 'csrf_token' => csrf_token()]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        app(RecoverAccount::class)->send($data['email'], ['customer']);

        return response()->json(['message' => 'Se esiste un account cliente con questa email, riceverai il link di recupero.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'token' => ['required', 'string', 'max:128'], 'password' => ['required', 'confirmed', Rules\Password::min(12), new SafePasswordLength]]);
        $status = app(RecoverAccount::class)->reset($data, ['customer']);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __(Password::INVALID_TOKEN)]);
        }

        return response()->json(['message' => 'Password aggiornata. Puoi accedere.']);
    }
}
