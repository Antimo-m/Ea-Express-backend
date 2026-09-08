<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafePasswordLength;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string'], 'remember' => ['sometimes', 'boolean']]);
        if (! Auth::guard('customer')->attempt(['email' => $data['email'], 'password' => $data['password'], 'role' => UserRole::Customer->value, 'is_active' => true], $data['remember'] ?? false)) {
            throw ValidationException::withMessages(['email' => 'Email o password non corrette.']);
        }
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'lowercase', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Rules\Password::min(12), new SafePasswordLength]]);
        $user = new User($data);
        $user->role = UserRole::Customer;
        $user->is_active = true;
        $user->save();
        Auth::guard('customer')->login($user);
        $request->session()->regenerate();

        return $this->me($request)->setStatusCode(201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('customer');

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'notify_orders' => $user->notify_orders, 'notify_messages' => $user->notify_messages], 'csrf_token' => csrf_token()]);
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
        if (User::where('email', $data['email'])->where('role', UserRole::Customer)->where('is_active', true)->exists()) {
            Password::sendResetLink($data);
        }

        return response()->json(['message' => 'Se esiste un account cliente con questa email, riceverai il link di recupero.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'confirmed', Rules\Password::min(12), new SafePasswordLength]]);
        $status = Password::reset([...$data, 'role' => UserRole::Customer->value], function (User $user, string $password): void {
            $user->password = Hash::make($password);
            $user->remember_token = Str::random(60);
            $user->save();
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password aggiornata. Puoi accedere.']);
    }
}
