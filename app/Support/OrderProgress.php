<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Collection;

class OrderProgress
{
    /**
     * Avanzamento calcolato dagli eventi registrati: per ogni stato vale l'ultimo passaggio, per lo stato attuale l'evento completo.
     *
     * @return array{steps: list<array<string, mixed>>, exception: ?array<string, mixed>, pricing: ?string}
     */
    public function current(Order $order): array
    {
        $events = $order->events()->whereNull('delay_action')->select('status')->selectRaw('MAX(created_at) as created_at')->groupBy('status')->get()->sortBy('created_at')->values();
        $currentEvent = $order->events()->whereNull('delay_action')->where('status', $order->status)->latest('created_at')->orderByDesc('id')->first();
        if ($currentEvent) {
            $events = $events->reject(fn ($event) => $event->status === $order->status)->push($currentEvent)->sortBy('created_at')->values();
        }

        return $this->forOrder($order, $events);
    }

    /**
     * Versione per clienti e tracking pubblico: niente note economiche interne, date in ISO 8601.
     *
     * @return array{position: int, state: string, steps: list<array{status: string, label: string, state: string, at: ?string, optional: bool, note: ?string}>, exception: ?array{status: string, label: string, note: ?string, at: ?string, last: ?string}}
     */
    public function forCustomer(Order $order): array
    {
        $progress = $this->current($order);
        $steps = array_map(fn (array $step): array => ['status' => $step['status'], 'label' => $step['label'], 'state' => $step['state'], 'at' => $step['at']?->toIso8601String(), 'optional' => $step['optional'], 'note' => $step['state'] === 'current' ? $step['public_note'] : null], $progress['steps']);
        $exception = $progress['exception'] ? ['status' => $progress['exception']['status'], 'label' => $progress['exception']['label'], 'note' => $progress['exception']['note'], 'at' => $progress['exception']['at']?->toIso8601String(), 'last' => $progress['exception']['last']] : null;

        return ['position' => $progress['position'], 'state' => $progress['state'], 'steps' => $steps, 'exception' => $exception];
    }

    /**
     * `position` è la tappa del camion: quella corrente oppure, se la spedizione è ferma o conclusa, l'ultima registrata.
     *
     * @return array{position: int, state: 'moving'|'arrived'|'paused'|'stopped', steps: list<array<string, mixed>>, exception: ?array<string, mixed>, pricing: ?string}
     */
    public function forOrder(Order $order, Collection $events): array
    {
        $workflow = OrderStatus::deliveryStages();
        if ($order->status === OrderStatus::PickupScheduled || $events->contains(fn ($event) => $event->status === OrderStatus::PickupScheduled)) {
            array_splice($workflow, 2, 0, [OrderStatus::PickupScheduled]);
        }
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
            $steps[] = ['status' => $status->value, 'label' => $status->label(), 'state' => $isCurrent ? 'current' : ($at ? 'complete' : 'future'), 'at' => $at, 'optional' => $status === OrderStatus::PickupScheduled, 'public_note' => $isCurrent ? $event?->public_note : null, 'description' => $isCurrent ? ($event?->public_note ?: 'Stato confermato da EA-Express.') : ($at ? 'Passaggio registrato' : 'Non ancora registrato')];
        }
        $exception = $current === false ? ['status' => $order->status->value, 'label' => $order->status->label(), 'tone' => $order->status->tone(), 'note' => $events->last(fn ($event) => $event->status === $order->status)?->public_note, 'at' => $events->last(fn ($event) => $event->status === $order->status)?->created_at, 'last' => $last?->status->label()] : null;
        $currentIndex = collect($steps)->search(fn (array $step): bool => $step['state'] === 'current');
        $reachedIndex = collect($steps)->filter(fn (array $step): bool => $step['state'] === 'complete')->keys()->last() ?? 0;
        $state = $exception ? (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Rejected], true) ? 'stopped' : 'paused') : ($currentIndex === false ? 'arrived' : 'moving');

        return ['position' => $currentIndex === false ? $reachedIndex : $currentIndex, 'state' => $state, 'steps' => $steps, 'exception' => $exception, 'pricing' => in_array($order->price_state, ['awaiting_customer', 'awaiting_rider', 'rejected', 'unavailable'], true) ? $order->priceLabel() : null];
    }
}
