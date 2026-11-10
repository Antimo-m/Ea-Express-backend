<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ReceiptLedger
{
    public const string CashExpression = 'COALESCE(cash_amount_cents, amount_cents)';

    public function cash(Builder $payments): int
    {
        return (int) (clone $payments)->selectRaw('COALESCE(SUM('.self::CashExpression.'), 0) AS cents')->value('cents');
    }

    /** @return array{gross: int, reversals: int, restorations: int, net: int, cash: int, refunds: int} */
    public function totals(Builder $payments): array
    {
        $gross = (int) (clone $payments)->where('amount_cents', '>', 0)->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'restoration'))->sum('amount_cents');
        $reversals = -(int) (clone $payments)->where('amount_cents', '<', 0)->sum('amount_cents');
        $restorations = (int) (clone $payments)->where('kind', 'restoration')->sum('amount_cents');
        $refunds = -(int) (clone $payments)->verifiedRefund()->sum('cash_amount_cents');

        return ['gross' => $gross, 'reversals' => $reversals, 'restorations' => $restorations, 'net' => $gross - $reversals + $restorations, 'cash' => $this->cash($payments), 'refunds' => $refunds];
    }
}
