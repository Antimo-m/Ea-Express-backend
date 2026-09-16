<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\Broadcast;

Broadcast::connection('reverb')->channel('customer.{id}', function (User $user, string $id): bool {
    return $user->is_active && $user->role === UserRole::Customer && (string) $user->id === $id;
}, ['guards' => ['customer']]);

Broadcast::connection('reverb')->channel('staff.{id}', function (User $user, string $id): bool {
    return $user->is_active && $user->isStaff() && (string) $user->id === $id;
}, ['guards' => ['web']]);
