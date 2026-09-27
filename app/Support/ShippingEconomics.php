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
        $gross = (int) (clone $payments)->sum('amount_cents');
        $retained = (int) (clone $payments)->selectRaw('COALESCE(SUM(COALESCE(ea_amount_cents, amount_cents)), 0) AS cents')->value('cents');
        $unallocated = (clone $payments)->whereNull('ea_amount_cents')->whereHas('order', fn ($orders) => $orders->where('shipping_type', 'external'))->count();

        return ['gross' => $gross, 'retained' => $retained, 'carrier' => $gross - $retained, 'unallocated' => $unallocated];
    }

    /** @return array<string,int> */
    public function summarize(Builder $orders): array
    {
        $active = (clone $orders)->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected]);
        $external = (clone $active)->where('shipping_type', 'external');
        $revenue = (int) (clone $external)->sum('price_cents');
        $cost = (int) (clone $external)->sum('carrier_cost_cents');

        return ['regional_count' => (clone $active)->where('shipping_type', 'regional')->count(), 'external_count' => (clone $external)->count(), 'external_revenue_cents' => $revenue, 'carrier_cost_cents' => $cost, 'external_margin_cents' => $revenue - $cost, 'shipping_margin_cents' => (int) (clone $active)->sum('price_cents') - $cost];
    }
}
