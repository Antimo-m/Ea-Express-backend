<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Actions\TransitionOrder;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'zone' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(OrderStatus::class)], 'urgency' => ['nullable', Rule::in(['urgent', 'standard'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $section = $request->route()->getName();
        $query = Order::visibleTo($request->user())->with('rider');
        $title = match ($section) {
            'orders.incoming' => 'Ordini in entrata', 'orders.in-progress' => 'Spedizioni in corso', default => 'Storico ordini'
        };
        if ($section === 'orders.incoming') {
            $query->where('status', OrderStatus::Received);
        } elseif ($section === 'orders.in-progress') {
            $query->whereNotIn('status', [...OrderStatus::closed(), OrderStatus::Received->value]);
        } else {
            $query->whereIn('status', OrderStatus::closed())->where('created_at', '>=', now()->subYear());
        }
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('store_name', 'like', '%'.$filters['q'].'%')->orWhere('reference', 'like', '%'.$filters['q'].'%'));
        }
        if (! empty($filters['zone'])) {
            $query->where('delivery_city', 'like', '%'.$filters['zone'].'%');
        }
        foreach (['status', 'urgency'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return view('orders.index', ['orders' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString(), 'title' => $title, 'section' => $section]);
    }

    public function create(): View
    {
        return view('orders.create');
    }

    public function store(StoreOrderRequest $request, NotifyOrderParticipants $notify): RedirectResponse
    {
        $order = DB::transaction(function () use ($request, $notify) {
            $order = new Order($request->validated());
            $order->reference = 'EA-'.Str::ulid();
            $order->tracking_token = Str::random(64);
            $order->created_by = $request->user()->id;
            $order->status = OrderStatus::Received;
            $order->save();
            $order->events()->create(['user_id' => $request->user()->id, 'status' => OrderStatus::Received]);

            $notify->handle($order, 'Nuova richiesta', $request->user()->id);

            return $order;
        });

        return redirect()->route('orders.show', $order)->with('status', 'Richiesta creata.');
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('orders.show', ['order' => $order->load('rider'), 'events' => $order->events()->with('user')->latest()->orderByDesc('id')->paginate(30)]);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $transition->handle($order, $request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Stato aggiornato.');
    }
}
