<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Actions\TransitionOrder;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'zone' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(OrderStatus::class)], 'urgency' => ['nullable', Rule::in(['urgent', 'standard'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'La data finale deve essere uguale o successiva alla data iniziale.']);
        }
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
            $query->where('created_at', '>=', Carbon::parse($filters['from'], 'Europe/Rome')->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'], 'Europe/Rome')->endOfDay()->utc());
        }

        return view('orders.index', ['orders' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString(), 'title' => $title, 'section' => $section]);
    }

    public function create(): View
    {
        return view('orders.create');
    }

    public function store(StoreOrderRequest $request, CreateOrder $create): RedirectResponse
    {
        $order = $create->handle($request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Richiesta creata.');
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('orders.show', ['order' => $order->load('rider'), 'transitions' => $order->allowedTransitions(), 'events' => $order->events()->with('user')->latest()->orderByDesc('id')->paginate(30)]);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $transition->handle($order, $request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Stato aggiornato.');
    }
}
