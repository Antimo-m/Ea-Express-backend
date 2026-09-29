<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Actions\TransitionOrder;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\CheckoutReview;
use App\Support\OrderSelection;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $section = $request->route()->getName();
        $selection = app(OrderSelection::class);
        $query = $selection->query($request, $section)->withDisplayIdentity()->with('rider');
        $title = match ($section) {
            'orders.incoming' => 'Ordini in entrata', 'orders.in-progress' => 'Spedizioni in corso', default => 'Storico ordini'
        };

        $orders = $query->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('orders.index', ['orders' => $orders, 'recipientRisks' => app(RecipientRisk::class)->forOrders($orders->getCollection()), 'title' => $title, 'section' => $section, 'currentCount' => $selection->query($request, 'orders.in-progress', false)->count()]);
    }

    public function create(): View
    {
        return view('orders.create', ['customers' => User::where('role', UserRole::Customer)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])]);
    }

    public function store(StoreOrderRequest $request, CreateOrder $create, CheckoutReview $review): RedirectResponse|View
    {
        if (! $request->filled('checkout_token')) {
            return view('orders.checkout', ['review' => $review->preview($request->user(), $request->validated())]);
        }

        try {
            $order = $create->handle($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            $exception->redirectTo(route('orders.create'));
            throw $exception;
        }

        return redirect()->route('orders.show', $order)->with('status', 'Richiesta creata.');
    }

    public function editCheckout(StoreOrderRequest $request): RedirectResponse
    {
        return redirect()->route('orders.create')->withInput($request->safe()->except('checkout_token'));
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('orders.show', ['recipientRisk' => app(RecipientRisk::class)->forOrders(collect([$order]))[$order->id] ?? ['count' => 0, 'last_at' => null], 'order' => $order->loadSum('payments', 'amount_cents')->load(['customer:id,name', 'creator:id,name', 'rider', 'priceProposals' => fn ($q) => $q->with(['proposer', 'responder'])->latest()]), 'riders' => auth()->user()->role === UserRole::Admin ? User::where('role', UserRole::Rider)->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(), 'transitions' => $order->allowedTransitions(), 'canReschedulePickup' => Order::awaitingPickup()->whereKey($order->id)->exists(), 'events' => $order->events()->with('user')->latest()->orderByDesc('id')->paginate(30)]);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $transition->handle($order, $request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Stato aggiornato.');
    }
}
