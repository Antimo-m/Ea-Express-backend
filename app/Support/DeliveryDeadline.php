<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Carbon;

class DeliveryDeadline
{
    /** @return array{at: ?string, label: ?string, overdue: bool, delivered_late: bool, elapsed: ?string} */
    public function forOrder(Order $order): array
    {
        $rescheduling = $order->events->last(fn ($event) => $event->status === OrderStatus::Rescheduled);
        $confirmed = $order->estimated_at?->copy();
        if (! $confirmed && $rescheduling?->schedule_change && isset($rescheduling->schedule_change['after']['estimated_at'])) {
            $confirmed = Carbon::parse($rescheduling->schedule_change['after']['estimated_at']);
        }
        $deadline = $confirmed ?? ($rescheduling ? null : $order->estimated_delivery_to?->copy()->timezone('Europe/Rome')->endOfDay());
        $closed = in_array($order->status->value, OrderStatus::closed(), true);
        $overdue = $deadline && ! $closed && now()->greaterThan($deadline);
        $late = $deadline && $order->status === OrderStatus::Delivered && $order->delivered_at?->greaterThan($deadline);

        return ['at' => $deadline?->toIso8601String(), 'label' => $deadline?->copy()->timezone('Europe/Rome')->format('d/m/Y H:i'), 'overdue' => (bool) $overdue, 'delivered_late' => (bool) $late, 'elapsed' => $overdue ? $deadline->diffForHumans(now(), true) : null];
    }
}
