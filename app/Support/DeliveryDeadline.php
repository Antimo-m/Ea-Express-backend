<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Carbon;

class DeliveryDeadline
{
    /** @return array{at: ?string, label: ?string, overdue: bool, delivered_late: bool, elapsed: ?string, history_complete: bool, reported_at: ?string, reported_label: ?string} */
    public function forOrder(Order $order, ?Carbon $reference = null): array
    {
        $reference ??= now('Europe/Rome');
        $historical = $reference->lt(now('Europe/Rome')->startOfDay());
        $changes = $order->relationLoaded('scheduleEvents') ? $order->scheduleEvents : $order->events->whereNull('delay_action')->sortBy(fn ($event) => $event->created_at->format('Y-m-d H:i:s').sprintf('%012d', $event->id));
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
        $reportedAt = $order->delay_reported_at?->copy();
        if ($historical) {
            $reports = $order->relationLoaded('delayEvents') ? $order->delayEvents : $order->delayEvents()->get();
            $report = $reports->last(fn ($event) => $event->created_at->lte($reference));
            $reportedAt = $report?->delay_action === 'reported' ? $report->created_at->copy() : null;
        }
        $overdue = $reportedAt && ! $closed && $reportedAt->lte($reference);
        $late = $reportedAt && $status === OrderStatus::Delivered && $reportedAt->lte($reference);

        return ['at' => $deadline?->toISOString(), 'label' => $deadline?->copy()->timezone('Europe/Rome')->format('d/m/Y H:i'), 'overdue' => (bool) $overdue, 'delivered_late' => (bool) $late, 'elapsed' => $overdue ? $reportedAt->diffForHumans($reference, true) : null, 'history_complete' => $complete, 'reported_at' => $reportedAt?->toISOString(), 'reported_label' => $reportedAt?->copy()->timezone('Europe/Rome')->format('d/m/Y H:i')];
    }
}
