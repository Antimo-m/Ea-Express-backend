<?php

namespace App\Actions;

use App\Models\User;
use App\Support\AccountSessionRevocation;
use App\Support\StaffAuthentication;
use App\UserRole;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Updates the editable profile fields. The account email is immutable after registration.
 */
class UpdateProfile
{
    /** @param array<string, mixed> $data */
    public function handle(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->role === $user->role, 403);
            app(AccountSessionRevocation::class)->assertCurrent(request(), $locked, $locked->role === UserRole::Customer ? 'customer' : 'web');
            app(StaffAuthentication::class)->assertComplete($locked);
            $locked->fill(Arr::only($data, ['name', 'first_name', 'last_name', 'sender_type', 'business_type', 'business_description']));
            $locked->save();
            $user->setRawAttributes($locked->getAttributes(), true);
        }, 3);
    }
}
