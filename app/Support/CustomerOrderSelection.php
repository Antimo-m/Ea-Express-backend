<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use App\SenderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerOrderSelection
{
    public function query(Request $request): Builder
    {
        $data = $request->validate(['shipping_type' => ['nullable', 'in:regional,external'], 'sender_type' => ['nullable', Rule::enum(SenderType::class)], 'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(OrderStatus::class)], 'kind' => ['nullable', 'in:pickup'], 'courier' => ['prohibited'], 'has_messages' => ['nullable', 'boolean'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = Order::where('customer_id', $request->user()->id);
        if (! empty($data['shipping_type'])) {
            $query->where('shipping_type', $data['shipping_type']);
        }
        if (! empty($data['sender_type'])) {
            $query->where('sender_type', $data['sender_type']);
        }
        if (! empty($data['q'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$data['q'].'%')->orWhere('store_name', 'like', '%'.$data['q'].'%')->orWhere('recipient_name', 'like', '%'.$data['q'].'%')->orWhere('delivery_city', 'like', '%'.$data['q'].'%'));
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

        if (! empty($data['from']) && ! empty($data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => 'La data finale precede quella iniziale.']);
        }

        return $query;
    }
}
