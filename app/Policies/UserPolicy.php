<?php

namespace App\Policies;

use App\Models\User;
use App\UserRole;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $target): bool
    {
        return $this->viewAny($user) && in_array($target->role, [UserRole::Rider, UserRole::Customer], true);
    }
}
