<?php

namespace App\Http\Controllers\Auth;

use App\Actions\ChangePassword;
use App\Http\Controllers\Controller;
use App\Rules\SafePasswordLength;
use App\Support\AccountSessionRevocation;
use App\Support\PasswordPolicy;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_if($request->user()->role === UserRole::Admin, 403, 'La password dell’Admin si gestisce soltanto dal terminale del server.');
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['bail', 'required', 'string', new SafePasswordLength, 'current_password'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ]);

        app(ChangePassword::class)->handle($request->user(), $validated['current_password'], $validated['password']);
        app(AccountSessionRevocation::class)->invalidateCurrent($request);

        return redirect()->route('login')->with('status', 'Password aggiornata. Accedi nuovamente.');
    }
}
