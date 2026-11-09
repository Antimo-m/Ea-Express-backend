<?php

namespace App\Http\Controllers\Auth;

use App\Actions\ChangePassword;
use App\Http\Controllers\Controller;
use App\Rules\SafePasswordLength;
use App\Support\PasswordPolicy;
use App\Support\StaffAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['bail', 'required', 'string', new SafePasswordLength, 'current_password'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ]);

        app(ChangePassword::class)->handle($request->user(), $validated['current_password'], $validated['password']);
        $request->session()->put('password_hash_web', $request->user()->password);
        $request->session()->regenerate(true);
        $request->session()->put('staff_authentication.binding', app(StaffAuthentication::class)->binding($request));
        $request->session()->put('staff_authentication.credentials', app(StaffAuthentication::class)->credentialProof($request->user()));

        return back()->with('status', 'password-updated');
    }
}
