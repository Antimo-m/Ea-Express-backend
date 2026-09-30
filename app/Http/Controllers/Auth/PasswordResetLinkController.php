<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RecoverAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        app(RecoverAccount::class)->send($request->input('email'), ['admin', 'rider']);

        return back()->with('status', 'Se esiste un account con questa email, riceverai il link di recupero.');
    }
}
