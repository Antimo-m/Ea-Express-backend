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

    /**
     * Totali degli incassi con una sola lettura per incassi, storni, ripristini e cassa; i rimborsi verificati
     * richiedono il controllo dell'attestazione e restano una query distinta.
     *
     * @return array{gross: int, reversals: int, restorations: int, net: int, cash: int, refunds: int}
     */
    public function totals(Builder $payments): array
    {
        $sums = (clone $payments)->toBase()->selectRaw(
            "COALESCE(SUM(CASE WHEN amount_cents > 0 AND (kind IS NULL OR kind <> 'restoration') THEN amount_cents ELSE 0 END), 0) AS gross, "
            .'COALESCE(SUM(CASE WHEN amount_cents < 0 THEN amount_cents ELSE 0 END), 0) AS negative, '
            ."COALESCE(SUM(CASE WHEN kind = 'restoration' THEN amount_cents ELSE 0 END), 0) AS restorations, "
            .'COALESCE(SUM('.self::CashExpression.'), 0) AS cash'
        )->first();
        $gross = (int) $sums->gross;
        $reversals = -(int) $sums->negative;
        $restorations = (int) $sums->restorations;
        $refunds = -(int) (clone $payments)->verifiedRefund()->sum('cash_amount_cents');

        return ['gross' => $gross, 'reversals' => $reversals, 'restorations' => $restorations, 'net' => $gross - $reversals + $restorations, 'cash' => (int) $sums->cash, 'refunds' => $refunds];
    }
}
