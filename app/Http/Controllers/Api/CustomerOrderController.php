<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateOrder;
use App\Actions\NotifyOrderParticipants;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerOrderRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Order;
use App\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(OrderStatus::class)], 'kind' => ['nullable', 'in:pickup'], 'courier' => ['nullable', 'integer', 'min:1'], 'has_messages' => ['nullable', 'boolean'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = Order::where('customer_id', $request->user()->id)->with('rider')->withCount(['messages', 'messages as unread_messages_count' => fn ($q) => $q->whereNotNull('user_id')->whereNull('read_at')]);
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

        return new CustomerOrderResource($order->load(['rider', 'events' => fn ($q) => $q->orderBy('id')]));
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
            abort_unless($locked->status === OrderStatus::Received && $locked->version === $request->integer('version'), 409, 'La richiesta è stata aggiornata o già accettata. Ricarica i dettagli.');
            $locked->fill($request->safe()->except(['version']));
            $locked->version++;
            $locked->save();
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
            abort_unless($locked->status === OrderStatus::Received && $locked->version === (int) $data['version'], 409, 'La richiesta è già stata gestita dal rider. Contattalo dalla conversazione.');
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
