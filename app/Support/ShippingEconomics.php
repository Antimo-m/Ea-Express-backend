<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ShippingEconomics
{
    public function retainedAmount(Order $order, int $received, ?string $amount): int
    {
        if ($order->shipping_type !== 'external') {
            return $received;
        }
        if ($amount === null || $amount === '') {
            return $received;
        }
        $retained = Money::cents($amount);
        if ($retained > $received) {
            throw ValidationException::withMessages(['ea_amount' => 'L’importo EA-Express non può superare l’incasso del cliente.']);
        }

        return $retained;
    }

    /** @return array{gross: int, retained: int, carrier: int, unallocated: int} */
    public function receipts(Builder $payments): array
    {
        $sums = (clone $payments)->toBase()->selectRaw('COALESCE(SUM(amount_cents), 0) AS gross, COALESCE(SUM(COALESCE(ea_amount_cents, amount_cents)), 0) AS retained')->first();
        $gross = (int) $sums->gross;
        $retained = (int) $sums->retained;
        $unallocated = (clone $payments)->whereNull('ea_amount_cents')->whereHas('order', fn ($orders) => $orders->where('shipping_type', 'external'))->count();

        return ['gross' => $gross, 'retained' => $retained, 'carrier' => $gross - $retained, 'unallocated' => $unallocated];
    }

    /** @return array<string,int> */
    public function summarize(Builder $orders): array
    {
        return $this->fromSums((clone $orders)->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->toBase()->selectRaw(self::sumColumns())->first());
    }

    /**
     * Colonne aggregate del riepilogo spedizioni, da unire ad altre somme sullo stesso insieme di ordini.
     * La condizione facoltativa restringe le somme alle righe che la soddisfano.
     */
    public static function sumColumns(string $condition = '1 = 1'): string
    {
        return "COALESCE(SUM(CASE WHEN ({$condition}) AND shipping_type = 'regional' THEN 1 ELSE 0 END), 0) AS economics_regional_count, "
            ."COALESCE(SUM(CASE WHEN ({$condition}) AND shipping_type = 'external' THEN 1 ELSE 0 END), 0) AS economics_external_count, "
            ."COALESCE(SUM(CASE WHEN ({$condition}) AND shipping_type = 'external' THEN price_cents END), 0) AS economics_external_revenue, "
            ."COALESCE(SUM(CASE WHEN ({$condition}) AND shipping_type = 'external' THEN carrier_cost_cents END), 0) AS economics_carrier_cost, "
            ."COALESCE(SUM(CASE WHEN ({$condition}) THEN price_cents END), 0) AS economics_active_revenue";
    }

    /** @return array<string,int> */
    public function fromSums(object $sums): array
    {
        $revenue = (int) $sums->economics_external_revenue;
        $cost = (int) $sums->economics_carrier_cost;

        return ['regional_count' => (int) $sums->economics_regional_count, 'external_count' => (int) $sums->economics_external_count, 'external_revenue_cents' => $revenue, 'carrier_cost_cents' => $cost, 'external_margin_cents' => $revenue - $cost, 'shipping_margin_cents' => (int) $sums->economics_active_revenue - $cost];
    }
}
