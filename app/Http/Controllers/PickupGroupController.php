<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PickupGroupController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'group' => ['nullable', 'string', 'size:64']]);
        $date = $data['date'] ?? now('Europe/Rome')->addDay()->toDateString();
        $orders = Order::visibleTo($request->user())->with('customer:id,name')->whereDate('pickup_date', $date)->whereIn('status', [OrderStatus::Received, OrderStatus::Accepted, OrderStatus::PickupScheduled, OrderStatus::RiderArriving])->orderBy('pickup_from')->orderBy('id')->get();
        $groups = $orders->groupBy(fn (Order $order) => hash('sha256', $order->customer_id ? 'customer:'.$order->customer_id : 'manual:'.$order->store_name.'|'.$order->contact_email.'|'.$order->pickup_address));
        $selected = isset($data['group']) ? $groups->get($data['group']) : null;
        abort_if(isset($data['group']) && ! $selected, 404);

        return view('pickups.index', compact('groups', 'selected', 'date'));
    }
}
