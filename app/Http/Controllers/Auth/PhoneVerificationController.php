<?php

namespace App\Http\Controllers\Auth;

use App\Actions\SendPhoneOtp;
use App\Actions\VerifyPhoneOtp;
use App\Http\Controllers\Controller;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PhoneVerificationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->phone_verified_at || $request->user()->role === UserRole::Admin) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-phone', ['user' => $request->user()]);
    }

    public function store(Request $request, SendPhoneOtp $send): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = preg_replace('/[\s()\-]/', '', $data['phone']);
        if (preg_match('/^3\d{9}$/D', $phone)) {
            $phone = '+39'.$phone;
        }
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        validator(['phone' => $phone], ['phone' => ['required', 'regex:/^\+[1-9]\d{7,14}$/D']])->validate();
        $send->handle($request->user(), $phone);

        return back()->with('status', 'Codice inviato via SMS. È valido per 15 minuti.');
    }

    public function update(Request $request, VerifyPhoneOtp $verify): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{4}$/D']]);
        $verify->handle($request->user(), $data['code']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Cellulare verificato. Dai prossimi accessi bastano email e password.');
    }
}
