<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RecoverAccount;
use App\Http\Controllers\Controller;
use App\Rules\SafePasswordLength;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [new SafePasswordLength, 'required', 'confirmed', Rules\Password::defaults(), 'max:72'],
        ]);

        $status = app(RecoverAccount::class)->reset($request->only('email', 'password', 'token'), ['admin', 'rider']);

        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __(Password::INVALID_TOKEN)]);
    }
}
