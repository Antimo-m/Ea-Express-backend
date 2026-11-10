<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateOrder;
use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerOrderRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\CheckoutReview;
use App\Support\CustomerIdentity;
use App\Support\CustomerOrderSelection;
use App\Support\Money;
use App\Support\RiderOperations;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = app(CustomerOrderSelection::class)->query($request)->withDisplayIdentity()->withReceiptStatus()->with('rider')
            ->withCount(['messages', 'messages as unread_messages_count' => fn ($q) => $q->whereNotNull('user_id')->whereNull('read_at')]);

        return CustomerOrderResource::collection($query->latest()->orderByDesc('id')->paginate(12)->withQueryString());
    }

    public function show(Request $request, Order $order): CustomerOrderResource
    {
        $this->authorizeOwner($request, $order);

        return new CustomerOrderResource($order->load(['customer:id,slug,name', 'creator:id,slug,name', 'rider', 'priceProposals' => fn ($q) => $q->latest(), 'events' => fn ($q) => $q->whereNull('delay_action')->orderBy('id')]));
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
            $customer = User::lockForUpdate()->findOrFail($request->user('customer')->id);
            abort_unless($customer->is_active && $customer->role === UserRole::Customer, 403);
            $validated = [...$request->validated(), ...CustomerIdentity::profile($customer)];
            $locked = Order::where('customer_id', $request->user()->id)->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->customerEditable() && $locked->version === $request->integer('version'), 409, 'La richiesta è stata aggiornata o già accettata. Ricarica i dettagli.');
            $review = app(CheckoutReview::class)->confirm($customer, $validated, $locked);
            $economicBefore = $locked->only(['price_cents', 'parcel_value_cents', 'shipping_rate_id', 'rate_snapshot']);
            $data = CustomerIdentity::normalize(Arr::except($validated, ['version', 'parcel_value', 'checkout_token', 'payment_method']), $locked->sender_type);
            $scheduleBefore = $locked->pickupSchedule();
            $locked->fill($data);
            foreach (['pickup', 'delivery'] as $kind) {
                if ($locked->isDirty([$kind.'_address', $kind.'_street_number', $kind.'_postal_code', $kind.'_city'])) {
                    $locked->{$kind.'_point'} = null;
                }
            }
            $scheduleAfter = $locked->pickupSchedule();
            $scheduleChange = $scheduleBefore === $scheduleAfter ? null : ['before' => $scheduleBefore, 'after' => $scheduleAfter];
            if ($scheduleChange) {
                $locked->pickup_reminded_on = null;
            }
            if ($locked->price_cents === null || $locked->isDirty(['shipping_type', 'weight_kg', 'max_dimension_cm', 'delivery_city', 'delivery_postal_code', 'delivery_zone', 'delivery_address', 'delivery_province', 'delivery_region', 'parcel_count', 'packages'])) {
                $before = $locked->rate_snapshot;
                app(ShippingQuote::class)->apply($locked, $review['quote']);
                $locked->price_cents = $review['quote']['price_cents'];
                $locked->price_state = 'agreed';
                app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'price.requoted', $before, $locked->rate_snapshot);
            }
            if ($request->exists('parcel_value')) {
                $locked->parcel_value_cents = $request->input('parcel_value') !== null ? Money::cents((string) $request->input('parcel_value')) : null;
            }
            app(ShippingQuote::class)->estimate($locked);
            $locked->version++;
            $locked->save();
            $economicAfter = $locked->only(['price_cents', 'parcel_value_cents', 'shipping_rate_id', 'rate_snapshot']);
            if ($economicBefore !== $economicAfter) {
                app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'order.economics_updated', $economicBefore, $economicAfter);
            }
            $locked->events()->create(['rider_id' => $locked->rider_id, 'operational_zone' => app(RiderOperations::class)->zone($locked), 'user_id' => $request->user()->id, 'status' => $locked->status, 'public_note' => $scheduleChange ? 'Data o fascia del ritiro aggiornata dal cliente.' : 'Richiesta aggiornata dal cliente.', 'schedule_change' => $scheduleChange]);
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
            app(ShippingQuote::class)->estimate($locked);
            $locked->version++;
            $locked->save();
            $locked->events()->create(['rider_id' => $locked->rider_id, 'operational_zone' => app(RiderOperations::class)->zone($locked), 'user_id' => $request->user()->id, 'status' => OrderStatus::Cancelled, 'public_note' => $data['reason']]);
            $notify->handle($locked, 'Richiesta annullata dal cliente', $request->user()->id);
        }, 3);

        return response()->json(['message' => 'Richiesta annullata.']);
    }

    private function authorizeOwner(Request $request, Order $order): void
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
    }
}
