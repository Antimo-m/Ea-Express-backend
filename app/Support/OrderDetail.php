<?php

namespace App\Support;

use App\Models\Order;
use App\UserRole;
use Illuminate\View\View;

class OrderDetail
{
    public function view(Order $order): View
    {
        return view('orders.show', ['recipientRisk' => app(RecipientRisk::class)->forOrders(collect([$order]))[$order->id] ?? ['count' => 0, 'last_at' => null], 'order' => $order->loadSum('payments', 'amount_cents')->load(['customer:id,slug,name', 'creator:id,slug,name', 'rider', 'priceProposals' => fn ($q) => $q->with(['proposer', 'responder'])->latest()]), 'riders' => auth()->user()->role === UserRole::Admin ? app(OperationalAssignees::class)->query(auth()->user())->orderBy('name')->orderBy('id')->get(['id', 'slug', 'name']) : collect(), 'transitions' => $order->allowedTransitions(), 'canReschedulePickup' => Order::awaitingPickup()->whereKey($order->id)->exists(), 'events' => $order->events()->with('user')->oldest()->orderBy('id')->paginate(30, ['*'], 'events_page')]);
    }
}
