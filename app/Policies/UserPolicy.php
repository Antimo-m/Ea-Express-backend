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
        return $this->viewAny($user) && $target->closed_at === null && in_array($target->role, [UserRole::Rider, UserRole::Customer], true);
    }

    /** L'Admin elimina i Rider; il proprio account si chiude dal profilo. */
    public function delete(User $user, User $target): bool
    {
        return $this->viewAny($user) && $target->closed_at === null && $target->role === UserRole::Rider;
    }
}
