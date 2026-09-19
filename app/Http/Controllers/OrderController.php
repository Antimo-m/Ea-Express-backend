<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Actions\TransitionOrder;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderSelection;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $section = $request->route()->getName();
        $selection = app(OrderSelection::class);
        $query = $selection->query($request, $section)->with('rider');
        $title = match ($section) {
            'orders.incoming' => 'Ordini in entrata', 'orders.in-progress' => 'Spedizioni in corso', default => 'Storico ordini'
        };

        return view('orders.index', ['orders' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString(), 'title' => $title, 'section' => $section, 'currentCount' => $selection->query($request, 'orders.in-progress', false)->count()]);
    }

    public function create(): View
    {
        return view('orders.create', ['customers' => User::where('role', UserRole::Customer)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])]);
    }

    public function store(StoreOrderRequest $request, CreateOrder $create): RedirectResponse
    {
        $order = $create->handle($request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Richiesta creata.');
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('orders.show', ['order' => $order->load(['rider', 'priceProposals' => fn ($q) => $q->with(['proposer', 'responder'])->latest()]), 'transitions' => $order->allowedTransitions(), 'events' => $order->events()->with('user')->latest()->orderByDesc('id')->paginate(30)]);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $transition->handle($order, $request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Stato aggiornato.');
    }
}
