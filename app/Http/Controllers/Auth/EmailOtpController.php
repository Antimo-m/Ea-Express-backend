<?php

namespace App\Http\Controllers\Auth;

use App\Actions\SendEmailOtp;
use App\Actions\VerifyEmailOtp;
use App\Http\Controllers\Controller;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailOtpController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->email_verified_at || $request->user()->role === UserRole::Admin) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email-otp', ['user' => $request->user()]);
    }

    public function store(Request $request, SendEmailOtp $send): RedirectResponse
    {
        $send->handle($request->user());

        return back()->with('status', 'Codice inviato via email. È valido per 15 minuti.');
    }

    public function update(Request $request, VerifyEmailOtp $verify): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);
        $verify->handle($request->user(), $data['code']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Email verificata. Dai prossimi accessi bastano email e password.');
    }
}
