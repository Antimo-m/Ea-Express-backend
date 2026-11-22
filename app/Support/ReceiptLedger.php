<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ReceiptLedger
{
    /** Denaro effettivamente mosso dal movimento, compresa l'eventuale parte del vettore. */
    public const string CashExpression = 'COALESCE(cash_amount_cents, amount_cents)';

    /** Effetto economico che spetta a EA-Express: per le spedizioni fuori regione esclude il costo vettore. */
    public const string ShareExpression = 'COALESCE(ea_amount_cents, amount_cents)';

    /**
     * Denaro che resta a EA-Express: il movimento di cassa meno la parte economica del vettore (importo − quota EA).
     * Per le spedizioni regionali coincide con il denaro mosso.
     */
    public const string ShareCashExpression = '(COALESCE(cash_amount_cents, amount_cents) - amount_cents + COALESCE(ea_amount_cents, amount_cents))';

    public function cash(Builder $payments): int
    {
        return (int) (clone $payments)->selectRaw('COALESCE(SUM('.self::CashExpression.'), 0) AS cents')->value('cents');
    }

    /** Denaro di competenza EA-Express, come lo conta il Bilancio. */
    public function shareCash(Builder $payments): int
    {
        return (int) (clone $payments)->selectRaw('COALESCE(SUM('.self::ShareCashExpression.'), 0) AS cents')->value('cents');
    }

    /**
     * Totali degli incassi con una sola lettura per incassi, storni, ripristini e cassa; i rimborsi verificati
     * richiedono il controllo dell'attestazione e restano una query distinta.
     *
     * @return array{gross: int, reversals: int, restorations: int, net: int, cash: int, refunds: int}
     */
    public function totals(Builder $payments): array
    {
        return $this->sum($payments, 'amount_cents', self::CashExpression);
    }

    /**
     * Gli stessi totali limitati alla quota EA-Express: è ciò che entra nel Bilancio e nei Report.
     *
     * @return array{gross: int, reversals: int, restorations: int, net: int, cash: int, refunds: int}
     */
    public function shareTotals(Builder $payments): array
    {
        return $this->sum($payments, self::ShareExpression, self::ShareCashExpression);
    }

    /** @return array{gross: int, reversals: int, restorations: int, net: int, cash: int, refunds: int} */
    private function sum(Builder $payments, string $economic, string $cash): array
    {
        $sums = (clone $payments)->toBase()->selectRaw(
            "COALESCE(SUM(CASE WHEN amount_cents > 0 AND (kind IS NULL OR kind <> 'restoration') THEN {$economic} ELSE 0 END), 0) AS gross, "
            ."COALESCE(SUM(CASE WHEN amount_cents < 0 THEN {$economic} ELSE 0 END), 0) AS negative, "
            ."COALESCE(SUM(CASE WHEN kind = 'restoration' THEN {$economic} ELSE 0 END), 0) AS restorations, "
            ."COALESCE(SUM({$cash}), 0) AS cash"
        )->first();
        $gross = (int) $sums->gross;
        $reversals = -(int) $sums->negative;
        $restorations = (int) $sums->restorations;
        $refunds = -(int) (clone $payments)->verifiedRefund()->sum('cash_amount_cents');

        return ['gross' => $gross, 'reversals' => $reversals, 'restorations' => $restorations, 'net' => $gross - $reversals + $restorations, 'cash' => (int) $sums->cash, 'refunds' => $refunds];
    }
}
