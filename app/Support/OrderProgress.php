<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Collection;

class OrderProgress
{
    /** @return array{steps: list<array<string, mixed>>, exception: ?array<string, mixed>, pricing: ?string} */
    public function forOrder(Order $order, Collection $events): array
    {
        $workflow = OrderStatus::deliveryStages();
        $current = array_search($order->status, $workflow, true);
        $last = $events->last(fn ($event) => in_array($event->status, $workflow, true));
        $steps = [];
        foreach ($workflow as $index => $status) {
            $event = $events->last(fn ($event) => $event->status === $status);
            $at = $event?->created_at;
            if (! $at && $status === OrderStatus::Received) {
                $at = $order->created_at;
            }
            if (! $at && $status === OrderStatus::Delivered && $order->status === $status) {
                $at = $order->delivered_at;
            }
            $isCurrent = $current === $index && $status !== OrderStatus::Delivered;
            $steps[] = ['status' => $status->value, 'label' => $status->label(), 'state' => $isCurrent ? 'current' : ($at ? 'complete' : 'future'), 'at' => $at, 'optional' => in_array($status, [OrderStatus::PickupScheduled, OrderStatus::InTransit], true), 'description' => $isCurrent ? ($event?->public_note ?: 'Stato confermato dal gestionale.') : ($at ? 'Passaggio registrato' : 'Non ancora registrato')];
        }
        $exception = $current === false ? ['label' => $order->status->label(), 'tone' => $order->status->tone(), 'note' => $events->last(fn ($event) => $event->status === $order->status)?->public_note, 'at' => $events->last(fn ($event) => $event->status === $order->status)?->created_at, 'last' => $last?->status->label()] : null;

        return ['steps' => $steps, 'exception' => $exception, 'pricing' => in_array($order->price_state, ['awaiting_customer', 'awaiting_rider', 'rejected', 'unavailable'], true) ? $order->priceLabel() : null];
    }
}
