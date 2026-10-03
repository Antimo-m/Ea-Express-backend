<?php

namespace App\Actions;

use App\Models\User;
use App\Support\SecurityEvent;
use App\Support\StaffAuthentication;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UpdateProfile
{
    /** @param array<string, mixed> $data */
    public function handle(User $user, array $data, #[\SensitiveParameter] ?string $currentPassword = null): bool
    {
        $emailChanged = DB::transaction(function () use ($user, $data, $currentPassword): bool {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active && $locked->role === $user->role, 403);
            app(StaffAuthentication::class)->assertComplete($locked);
            $locked->fill(Arr::only($data, ['name', 'email', 'sender_type', 'business_type', 'business_description']));
            $emailChanged = $locked->isDirty('email');
            if ($emailChanged && ($currentPassword === null || ! Hash::check($currentPassword, $locked->password))) {
                throw ValidationException::withMessages(['current_password' => 'Per cambiare email, inserisci la password attuale corretta.']);
            }
            $locked->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $emailChanged;
        }, 3);

        if ($emailChanged) {
            SecurityEvent::record('email_changed', $user->id);
        }

        return $emailChanged;
    }
}
