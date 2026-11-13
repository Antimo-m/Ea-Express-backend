<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\SafePasswordLength;
use App\Support\PasswordPolicy;
use App\Support\PasswordRecovery;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Password recovery for riders. The Admin password is managed only from the server console.
 */
class PasswordRecoveryController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $recovery->request($request, $data['email'], UserRole::Rider);

        return back()->with('status', PasswordRecovery::RequestedMessage);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '']);
    }

    public function update(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'token' => ['required', 'string', 'max:128'], 'password' => [...PasswordPolicy::rules(), 'confirmed'], 'password_confirmation' => ['required', 'string', new SafePasswordLength]]);
        $recovery->reset($request, $data, UserRole::Rider);

        return redirect()->route('login')->with('status', 'Password reimpostata. Accedi con la nuova password e conferma il codice di verifica.');
    }
}
