<?php

namespace App\Http\Controllers;

use App\Actions\CloseStaffAccount;
use App\Actions\UpdateProfile;
use App\Http\Requests\ProfileUpdateRequest;
use App\Support\AccountSessionRevocation;
use App\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateProfile $update): RedirectResponse
    {
        $update->handle($request->user(), $request->safe()->only(['name']));

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * L'Admin elimina il proprio account confermando password e operazione; i Rider non possono farlo.
     */
    public function destroy(Request $request, CloseStaffAccount $close, AccountSessionRevocation $sessions): RedirectResponse
    {
        if ($request->user()->role !== UserRole::Admin) {
            throw new AuthorizationException;
        }
        $request->validateWithBag('accountClosure', [
            'password' => ['required', 'string', 'current_password:web'],
            'confirmation' => ['required', 'string', 'in:ELIMINA'],
        ], [
            'password.current_password' => 'La password non è corretta.',
            'confirmation.in' => 'Scrivi ELIMINA in maiuscolo per confermare.',
        ]);
        $close->handle($request->user(), $request->user());
        $sessions->invalidateCurrent($request);

        return redirect()->route('login')->with('status', 'Account eliminato. Tutte le sessioni sono state chiuse.');
    }
}
