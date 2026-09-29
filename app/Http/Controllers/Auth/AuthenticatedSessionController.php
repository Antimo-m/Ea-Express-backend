<?php

namespace App\Http\Controllers\Auth;

use App\Actions\SendEmailOtp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if ($request->user()->role === UserRole::Rider && ! $request->user()->email_verified_at) {
            try {
                app(SendEmailOtp::class)->handle($request->user());
            } catch (ValidationException $exception) {
                return redirect()->route('email-otp.notice')->withErrors($exception->errors());
            }

            return redirect()->route('email-otp.notice');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
