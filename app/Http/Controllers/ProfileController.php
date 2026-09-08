<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderMessage;
use App\Models\PaymentEntry;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
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
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (Expense::where('user_id', $user->id)->exists() || PaymentEntry::where('user_id', $user->id)->exists() || OrderMessage::where('user_id', $user->id)->exists() || $user->role === UserRole::Admin || Order::query()->where('created_by', $user->id)->orWhere('rider_id', $user->id)->orWhere('rejected_by', $user->id)->exists() || OrderEvent::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['password' => 'L’account ha responsabilità o uno storico operativo. Contatta il responsabile per disabilitarlo.'])->errorBag('userDeletion');
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
