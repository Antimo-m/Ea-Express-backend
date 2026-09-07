<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrackingController extends Controller
{
    public function index(Request $request): View
    {
        return view('tracking.index', ['orders' => Order::visibleTo($request->user())->whereNotNull('tracking_started_at')->latest('updated_at')->orderByDesc('id')->paginate(15)]);
    }

    public function show(string $token): View
    {
        $order = Order::query()->where('tracking_token', $token)->whereNotNull('tracking_started_at')->firstOrFail();

        return view('tracking.public', ['reference' => $order->reference, 'status' => $order->status, 'estimated' => $order->estimated_at, 'events' => $order->events()->select(['id', 'status', 'public_note', 'created_at'])->latest()->orderByDesc('id')->paginate(30)]);
    }
}
