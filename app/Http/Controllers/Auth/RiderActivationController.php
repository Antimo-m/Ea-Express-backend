<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\SafePasswordLength;
use App\Support\PasswordPolicy;
use App\Support\RiderActivation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RiderActivationController extends Controller
{
    public function edit(Request $request, string $token): View
    {
        return view('auth.activate-rider', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '']);
    }

    public function update(Request $request, RiderActivation $activation): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'token' => ['required', 'string', 'max:128'], 'password' => [...PasswordPolicy::rules(), 'confirmed'], 'password_confirmation' => ['required', 'string', new SafePasswordLength]]);
        $activation->activate($request, $data);

        return redirect()->route('login')->with('status', 'Password creata. Accedi con la tua email e conferma il codice di verifica che ti invieremo.');
    }
}
