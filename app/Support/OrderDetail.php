<?php

namespace App\Support;

use App\Models\Order;
use App\UserRole;
use Illuminate\View\View;

class OrderDetail
{
    public function view(Order $order): View
    {
        $progressEvents = $order->events()->whereNull('delay_action')->select('status')->selectRaw('MAX(created_at) as created_at')->groupBy('status')->get()->sortBy('created_at')->values();
        $currentEvent = $order->events()->whereNull('delay_action')->where('status', $order->status)->latest('created_at')->orderByDesc('id')->first();
        if ($currentEvent) {
            $progressEvents = $progressEvents->reject(fn ($event) => $event->status === $order->status)->push($currentEvent)->sortBy('created_at')->values();
        }

        return view('orders.show', ['receipt' => $order->receiptData(), 'progress' => app(OrderProgress::class)->forOrder($order, $progressEvents), 'recipientRisk' => app(RecipientRisk::class)->forOrders(collect([$order]))[$order->id] ?? ['count' => 0, 'last_at' => null], 'order' => $order->load(['customer:id,slug,name', 'creator:id,slug,name', 'rider', 'priceProposals' => fn ($q) => $q->with(['proposer', 'responder'])->latest()]), 'riders' => auth()->user()->role === UserRole::Admin ? app(OperationalAssignees::class)->query(auth()->user())->orderBy('name')->orderBy('id')->get(['id', 'slug', 'name']) : collect(), 'transitions' => $order->allowedTransitions(), 'events' => $order->events()->whereNull('delay_action')->with('user')->oldest()->orderBy('id')->paginate(30, ['*'], 'events_page')]);
    }
}
