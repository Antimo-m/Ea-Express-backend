<?php

namespace App\Http\Controllers\Auth;

use App\Actions\SendEmailOtp;
use App\Actions\VerifyEmailOtp;
use App\Http\Controllers\Controller;
use App\StaffAuthenticationState;
use App\Support\IntendedDestination;
use App\Support\StaffAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailOtpController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (app(StaffAuthentication::class)->state($request, $request->user()) === StaffAuthenticationState::FullyAuthenticated) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email-otp', ['user' => $request->user()]);
    }

    public function store(Request $request, SendEmailOtp $send): RedirectResponse
    {
        $send->handle($request->user());

        return back()->with('status', 'Codice inviato via email. È valido per '.(int) ceil(config('auth_security.otp.ttl_seconds') / 60).' minuti.');
    }

    public function update(Request $request, VerifyEmailOtp $verify): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);
        $verify->handle($request->user(), $data['code']);
        app(StaffAuthentication::class)->complete($request, $request->user()->refresh());

        return redirect(IntendedDestination::take($request))->with('status', 'Accesso verificato.');
    }
}
