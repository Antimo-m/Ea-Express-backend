<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Carbon;

class DeliveryDeadline
{
    /** @return array{at: ?string, label: ?string, overdue: bool, delivered_late: bool, elapsed: ?string, history_complete: bool} */
    public function forOrder(Order $order, ?Carbon $reference = null): array
    {
        $reference ??= now('Europe/Rome');
        $historical = $reference->lt(now('Europe/Rome')->startOfDay());
        $changes = $order->relationLoaded('scheduleEvents') ? $order->scheduleEvents : $order->events->sortBy(fn ($event) => $event->created_at->format('Y-m-d H:i:s').sprintf('%012d', $event->id));
        $rescheduling = $changes->last(fn ($event) => $event->created_at->lte($reference) && ($event->status === OrderStatus::Rescheduled || array_key_exists('deadline_at', $event->schedule_change['after'] ?? [])));
        $future = $historical ? $changes->first(fn ($event) => $event->created_at->gt($reference) && ($event->status === OrderStatus::Rescheduled || array_key_exists('deadline_at', $event->schedule_change['before'] ?? []))) : null;
        $deadline = null;
        $complete = true;
        if ($rescheduling) {
            $value = $rescheduling->schedule_change['after']['deadline_at'] ?? $rescheduling->schedule_change['after']['estimated_at'] ?? (! $historical && $order->status === OrderStatus::Rescheduled ? $order->estimated_at?->toISOString() : null);
            $deadline = $value ? Carbon::parse($value) : null;
            $complete = $value !== null;
        } elseif ($future) {
            $before = $future->schedule_change['before'] ?? [];
            $value = $before['deadline_at'] ?? $before['estimated_at'] ?? null;
            $deadline = $value ? Carbon::parse($value) : null;
            $complete = array_key_exists('deadline_at', $before) || $value !== null;
        } else {
            $deadline = $order->estimated_at?->copy() ?? $order->estimated_delivery_to?->copy()->timezone('Europe/Rome')->endOfDay();
        }
        $event = $order->events->last(fn ($event) => $event->created_at->lte($reference));
        $status = $historical ? ($event?->status ?? ($order->updated_at?->lte($reference) ? $order->status : null)) : $order->status;
        if ($order->delivered_at?->lte($reference)) {
            $status = OrderStatus::Delivered;
        }
        if ($historical && ! $status) {
            $complete = false;
        }
        $closed = ! $status || in_array($status->value, OrderStatus::closed(), true);
        $overdue = $deadline && ! $closed && $order->created_at?->lte($reference) && $reference->greaterThan($deadline);
        $deliveryChange = $order->delivered_at ? $changes->last(fn ($event) => $event->created_at->lte($order->delivered_at) && ($event->status === OrderStatus::Rescheduled || array_key_exists('deadline_at', $event->schedule_change['after'] ?? []))) : null;
        $deliveryValue = $deliveryChange?->schedule_change['after']['deadline_at'] ?? $deliveryChange?->schedule_change['after']['estimated_at'] ?? null;
        $deliveryDeadline = $deliveryChange ? ($deliveryValue ? Carbon::parse($deliveryValue) : null) : ($order->estimated_at?->copy() ?? $order->estimated_delivery_to?->copy()->timezone('Europe/Rome')->endOfDay());
        $late = $deliveryDeadline && $order->status === OrderStatus::Delivered && $order->delivered_at?->greaterThan($deliveryDeadline);

        return ['at' => $deadline?->toISOString(), 'label' => $deadline?->copy()->timezone('Europe/Rome')->format('d/m/Y H:i'), 'overdue' => (bool) $overdue, 'delivered_late' => (bool) $late, 'elapsed' => $overdue ? $deadline->diffForHumans($reference, true) : null, 'history_complete' => $complete];
    }
}
