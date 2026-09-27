<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PickupGroupController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['view' => ['nullable', 'in:today,tomorrow,overdue,date'], 'date' => ['required_if:view,date', 'nullable', 'date_format:Y-m-d'], 'group' => ['nullable', 'string', 'size:64']]);
        $mode = $data['view'] ?? (isset($data['date']) ? 'date' : 'today');
        $today = now('Europe/Rome')->toDateString();
        $tomorrow = now('Europe/Rome')->addDay()->toDateString();
        $date = match ($mode) {
            'tomorrow' => $tomorrow, 'date' => $data['date'], default => $today
        };
        $base = Order::visibleTo($request->user())->awaitingPickup();
        $counts = ['today' => (clone $base)->where('pickup_date', '>=', $today)->where('pickup_date', '<', $tomorrow)->count(), 'tomorrow' => (clone $base)->where('pickup_date', '>=', $tomorrow)->where('pickup_date', '<', now('Europe/Rome')->addDays(2)->toDateString())->count(), 'overdue' => (clone $base)->where('pickup_date', '<', $today)->count()];
        $query = (clone $base)->withDisplayIdentity();
        $mode === 'overdue' ? $query->where('pickup_date', '<', $today) : $query->where('pickup_date', '>=', $date)->where('pickup_date', '<', Carbon::parse($date)->addDay()->toDateString());
        $orders = $query->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->paginate(50)->appends($request->except('group'));
        $groups = $orders->getCollection()->groupBy(fn (Order $order) => hash('sha256', $order->customer_id ? 'customer:'.$order->customer_id : 'manual:'.$order->store_name.'|'.$order->contact_email.'|'.$order->pickup_address));
        $selected = isset($data['group']) ? $groups->get($data['group']) : null;
        abort_if(isset($data['group']) && ! $selected, 404);

        return view('pickups.index', compact('groups', 'selected', 'date', 'mode', 'counts', 'orders'));
    }
}
