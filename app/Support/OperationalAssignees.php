<?php

namespace App\Support;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class OperationalAssignees
{
    /** @return EloquentBuilder<User> */
    public function query(User $actor): EloquentBuilder
    {
        $query = User::query();
        $this->constrain($query, $actor);

        return $query;
    }

    public function rule(User $actor): Exists
    {
        return Rule::exists(User::class, 'id')->where(fn (Builder $query) => $this->constrain($query, $actor));
    }

    private function constrain(Builder|EloquentBuilder $query, User $actor): void
    {
        $query->where('is_active', true)->where(function (Builder|EloquentBuilder $roles) use ($actor): void {
            $roles->where('role', UserRole::Rider);
            if ($actor->is_active && $actor->role === UserRole::Admin) {
                $roles->orWhere(fn (Builder|EloquentBuilder $self) => $self->where('role', UserRole::Admin)->where('id', $actor->id));
            }
        });
    }
}
