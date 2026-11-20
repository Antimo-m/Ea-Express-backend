<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use App\UserRole;
use Illuminate\View\View;

class OrderDetail
{
    public function view(Order $order): View
    {
        return view('orders.show', ['receipt' => $order->receiptData(), 'progress' => app(OrderProgress::class)->current($order), 'recipientRisk' => app(RecipientRisk::class)->forOrders(collect([$order]))[$order->id] ?? ['count' => 0, 'last_at' => null], 'order' => $order->load(['customer:id,slug,name', 'creator:id,slug,name', 'rider', 'priceProposals' => fn ($q) => $q->with(['proposer', 'responder'])->latest()]), 'riders' => auth()->user()->role === UserRole::Admin ? app(OperationalAssignees::class)->query(auth()->user())->orderBy('name')->orderBy('id')->get(['id', 'slug', 'name']) : collect(), 'transitions' => collect($order->allowedTransitions())->reject(fn (OrderStatus $status): bool => $status === OrderStatus::Cancelled && in_array($order->status, OrderStatus::transport(), true) && auth()->user()->role !== UserRole::Admin)->values()->all(), 'events' => $order->events()->whereNull('delay_action')->with('user')->oldest()->orderBy('id')->paginate(30, ['*'], 'events_page')]);
    }
}
