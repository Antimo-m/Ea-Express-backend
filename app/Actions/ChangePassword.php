<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePassword
{
    public function handle(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($user, $currentPassword, $password): void {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->is_active, 403);
            if (! Hash::check($currentPassword, $locked->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')]);
            }
            $locked->password = Hash::make($password);
            $locked->save();
            $user->setRawAttributes($locked->getAttributes(), true);
        }, 3);
    }
}
