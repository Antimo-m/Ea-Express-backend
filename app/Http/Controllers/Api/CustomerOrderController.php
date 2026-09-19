<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateOrder;
use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerOrderRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Order;
use App\OrderStatus;
use App\Support\CheckoutReview;
use App\Support\CustomerIdentity;
use App\Support\Money;
use App\Support\ShippingQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['sender_type' => ['nullable', 'in:business,private,online_shop'], 'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(OrderStatus::class)], 'kind' => ['nullable', 'in:pickup'], 'courier' => ['nullable', 'integer', 'min:1'], 'has_messages' => ['nullable', 'boolean'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = Order::where('customer_id', $request->user()->id)->with('rider')->withCount(['messages', 'messages as unread_messages_count' => fn ($q) => $q->whereNotNull('user_id')->whereNull('read_at')]);
        if (! empty($data['sender_type'])) {
            $query->where('sender_type', $data['sender_type']);
        }
        if (! empty($data['q'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$data['q'].'%')->orWhere('recipient_name', 'like', '%'.$data['q'].'%')->orWhere('delivery_city', 'like', '%'.$data['q'].'%'));
        }
        if (! empty($data['courier'])) {
            $query->where('rider_id', $data['courier']);
        }
        if ($request->boolean('has_messages')) {
            $query->whereHas('messages');
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (($data['kind'] ?? null) === 'pickup') {
            $query->whereNotIn('status', OrderStatus::closed())->whereDoesntHave('events', fn ($q) => $q->where('status', OrderStatus::PickedUp));
        }
        if (! empty($data['from'])) {
            $query->whereDate('pickup_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('pickup_date', '<=', $data['to']);
        }

        return CustomerOrderResource::collection($query->latest()->orderByDesc('id')->paginate(12)->withQueryString());
    }

    public function show(Request $request, Order $order): CustomerOrderResource
    {
        $this->authorizeOwner($request, $order);

        return new CustomerOrderResource($order->load(['rider', 'priceProposals' => fn ($q) => $q->latest(), 'events' => fn ($q) => $q->orderBy('id')]));
    }

    public function review(CustomerOrderRequest $request, CheckoutReview $review, ?Order $order = null): JsonResponse
    {
        if ($order) {
            $this->authorizeOwner($request, $order);
            abort_unless($order->customerEditable(), 409);
        }

        return response()->json($review->preview($request->user(), $request->validated(), $order));
    }

    public function store(CustomerOrderRequest $request, CreateOrder $create): CustomerOrderResource
    {
        return new CustomerOrderResource($create->handle($request->user(), $request->validated())->load('rider'));
    }

    public function update(CustomerOrderRequest $request, Order $order, NotifyOrderParticipants $notify): CustomerOrderResource
    {
        $this->authorizeOwner($request, $order);
        $updated = DB::transaction(function () use ($request, $order, $notify): Order {
            $locked = Order::where('customer_id', $request->user()->id)->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->customerEditable() && $locked->version === $request->integer('version'), 409, 'La richiesta è stata aggiornata o già accettata. Ricarica i dettagli.');
            $review = app(CheckoutReview::class)->confirm($request->user(), $request->validated(), $locked);
            $economicBefore = $locked->only(['price_cents', 'parcel_value_cents', 'payment_method', 'shipping_rate_id', 'rate_snapshot']);
            $data = CustomerIdentity::normalize($request->safe()->except(['version', 'parcel_value', 'checkout_token', 'payment_method']), $locked->sender_type);
            if ($locked->payment_method !== $request->validated('payment_method')) {
                $locked->payment_method = $request->validated('payment_method');
                $locked->payment_proposed_by = $request->user()->id;
                $locked->payment_proposed_at = now();
                $locked->payment_confirmed_by = null;
                $locked->payment_confirmed_at = null;
            }
            $locked->fill($data);
            if ($locked->price_cents === null || $locked->isDirty(['delivery_city', 'delivery_postal_code', 'delivery_zone', 'delivery_address'])) {
                $before = $locked->rate_snapshot;
                app(ShippingQuote::class)->apply($locked, $review['quote']);
                $locked->price_cents = $review['quote']['price_cents'];
                $locked->price_state = 'agreed';
                app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'price.requoted', $before, $locked->rate_snapshot);
            }
            if ($request->exists('parcel_value')) {
                $locked->parcel_value_cents = $request->input('parcel_value') !== null ? Money::cents((string) $request->input('parcel_value')) : null;
            }
            $locked->version++;
            $locked->save();
            $economicAfter = $locked->only(['price_cents', 'parcel_value_cents', 'payment_method', 'shipping_rate_id', 'rate_snapshot']);
            if ($economicBefore !== $economicAfter) {
                app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'order.economics_updated', $economicBefore, $economicAfter);
            }
            $locked->events()->create(['user_id' => $request->user()->id, 'status' => $locked->status, 'public_note' => 'Richiesta aggiornata dal cliente.']);
            $notify->handle($locked, 'Richiesta modificata dal cliente', $request->user()->id);

            return $locked;
        }, 3);

        return new CustomerOrderResource($updated->load('rider'));
    }

    public function cancel(Request $request, Order $order, NotifyOrderParticipants $notify): JsonResponse
    {
        $this->authorizeOwner($request, $order);
        $data = $request->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $order, $data, $notify): void {
            $locked = Order::where('customer_id', $request->user()->id)->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->customerEditable() && $locked->version === (int) $data['version'], 409, 'La richiesta è già stata gestita dal rider. Contattalo dalla conversazione.');
            $locked->status = OrderStatus::Cancelled;
            $locked->version++;
            $locked->save();
            $locked->events()->create(['user_id' => $request->user()->id, 'status' => OrderStatus::Cancelled, 'public_note' => $data['reason']]);
            $notify->handle($locked, 'Richiesta annullata dal cliente', $request->user()->id);
        }, 3);

        return response()->json(['message' => 'Richiesta annullata.']);
    }

    private function authorizeOwner(Request $request, Order $order): void
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
    }
}
