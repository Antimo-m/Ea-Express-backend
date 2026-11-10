<?php

namespace App\Http\Controllers;

use App\Actions\UpdateProfile;
use App\Http\Requests\ProfileUpdateRequest;
use App\Support\AccountSessionRevocation;
use App\Support\StaffAuthentication;
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
        $data = $request->validated();
        if ($update->handle($request->user(), $request->safe()->only(['name', 'email']), $data['current_password'] ?? null)) {
            $request->session()->regenerate(true);
            app(AccountSessionRevocation::class)->authorize($request, $request->user(), 'web');
            app(StaffAuthentication::class)->begin($request, $request->user());
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort(403, 'La chiusura degli account è gestita esclusivamente dall’Admin.');
    }
}
