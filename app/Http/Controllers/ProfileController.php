<?php

namespace App\Http\Controllers;

use App\Actions\UpdateProfile;
use App\Http\Requests\ProfileUpdateRequest;
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

    public function destroy(Request $request): RedirectResponse
    {
        abort(403, 'La chiusura degli account è gestita esclusivamente dall’Admin.');
    }
}
