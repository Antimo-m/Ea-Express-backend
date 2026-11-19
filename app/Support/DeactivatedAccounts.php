<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Identifies disabled accounts past the review period and describes what a retention step would
 * have to preserve. It never deletes or anonymises data.
 */
class DeactivatedAccounts
{
    public static function days(): int
    {
        return max(1, (int) config('account_retention.deactivated_days', 90));
    }

    public static function reviewDate(User $user): ?Carbon
    {
        return $user->is_active || ! $user->deactivated_at ? null : $user->deactivated_at->copy()->addDays(self::days());
    }

    /** @return Builder<User> */
    public function due(?Carbon $now = null): Builder
    {
        return User::query()->where('role', '!=', UserRole::Admin->value)->where('is_active', false)->whereNull('closed_at')
            ->whereNotNull('deactivated_at')->where('deactivated_at', '<=', ($now ?? now())->copy()->subDays(self::days()));
    }

    /** @return array{orders: int, financial: int, pending: int, proposal: string} */
    public function retention(User $user): array
    {
        $orders = Order::query()->where(fn ($query) => $query->where('customer_id', $user->id)->orWhere('rider_id', $user->id)->orWhere('created_by', $user->id))->count();
        $financial = PaymentEntry::where('user_id', $user->id)->count() + Expense::where('user_id', $user->id)->count() + FinancialMovement::where('user_id', $user->id)->orWhere('customer_id', $user->id)->count();
        $pending = PendingAccount::where('customer_id', $user->id)->whereIn('state', ['open', 'partially_paid'])->count();

        return [
            'orders' => $orders, 'financial' => $financial, 'pending' => $pending,
            'proposal' => match (true) {
                $pending > 0 => 'Bloccato: sospesi aperti da regolare',
                $orders + $financial > 0 => 'Anonimizzazione con conservazione di ordini e contabilità',
                default => 'Eliminazione possibile: nessun dato collegato',
            },
        ];
    }
}
