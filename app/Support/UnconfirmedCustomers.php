<?php

namespace App\Support;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Customer accounts that never confirmed their email with the first-access OTP and never produced data.
 * Only these can be registered again or pruned, so an existing customer is never overwritten or removed.
 */
class UnconfirmedCustomers
{
    private const OwnedTables = ['orders', 'pending_accounts', 'financial_movements', 'sender_addresses'];

    /** @return Builder<User> */
    public static function query(): Builder
    {
        $query = User::query()->where('role', UserRole::Customer)->whereNull('email_verified_at');
        foreach (self::OwnedTables as $table) {
            $query->whereNotExists(fn (QueryBuilder $owned) => $owned->selectRaw('1')->from($table)->whereColumn($table.'.customer_id', 'users.id'));
        }

        return $query;
    }

    public static function includes(User $user): bool
    {
        return self::query()->whereKey($user->id)->exists();
    }
}
