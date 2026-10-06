<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Actions\TransitionOrder;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\CheckoutReview;
use App\Support\OrderDetail;
use App\Support\OrderSelection;
use App\Support\PublicReferences;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $section = $request->route()->getName();
        $selection = app(OrderSelection::class);
        $query = $selection->query($request, $section)->withDisplayIdentity()->with('rider');
        if ($section !== 'orders.history') {
            $query->with(['events' => fn ($events) => $events->latest()->orderByDesc('id')->limit(4)]);
        }
        $title = match ($section) {
            'orders.incoming' => 'Ordini in entrata', 'orders.in-progress' => 'Spedizioni in corso', default => 'Storico ordini'
        };

        $orders = $query->latest()->orderByDesc('id')->paginate(20)->withQueryString();

        if ($orders->currentPage() > $orders->lastPage()) {
            return redirect()->route($section, [...$request->query(), 'page' => $orders->lastPage()]);
        }

        return view('orders.index', ['orders' => $orders, 'recipientRisks' => app(RecipientRisk::class)->forOrders($orders->getCollection()), 'title' => $title, 'section' => $section, 'customers' => User::where('role', UserRole::Customer)->whereIn('id', $selection->query($request, $section, false)->select('customer_id'))->orderBy('name')->get(['id', 'slug', 'name', 'email']), 'riders' => User::whereIn('id', $selection->query($request, $section, false)->select('rider_id'))->orderBy('name')->get(['id', 'slug', 'name', 'email']), 'currentCount' => $selection->query($request, 'orders.in-progress', false)->count()]);
    }

    public function create(): View
    {
        return view('orders.create', ['customers' => User::where('role', UserRole::Customer)->where('is_active', true)->orderBy('name')->get(['id', 'slug', 'name', 'email', 'sender_type', 'business_type', 'business_description'])]);
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

        return app(OrderDetail::class)->view($order);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, TransitionOrder $transition): RedirectResponse|JsonResponse
    {
        $transition->handle($order, $request->user(), PublicReferences::ids($request->validated()));

        if ($request->expectsJson()) {
            $order->refresh();

            return response()->json(['message' => 'Stato aggiornato.', 'status' => $order->status->value, 'version' => $order->version, 'html' => app(OrderDetail::class)->view($order)->render()]);
        }

        return redirect()->route('orders.show', $order)->with('status', 'Stato aggiornato.');
    }
}
