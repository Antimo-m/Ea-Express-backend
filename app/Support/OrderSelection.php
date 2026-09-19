<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderSelection
{
    public function query(Request $request, string $section, bool $filtered = true): Builder
    {
        $filters = $request->validate(['sender_type' => ['nullable', 'in:business,private,online_shop'], 'q' => ['nullable', 'string', 'max:100'], 'zone' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(OrderStatus::class)], 'urgency' => ['nullable', 'in:urgent,standard'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'La data finale precede quella iniziale.']);
        }
        $query = Order::visibleTo($request->user());
        if ($section === 'orders.incoming') {
            $query->where('status', OrderStatus::Received);
        } elseif ($section === 'orders.in-progress') {
            $query->whereNotIn('status', [...OrderStatus::closed(), OrderStatus::Received->value]);
        } else {
            $query->whereIn('status', OrderStatus::closed())->where('created_at', '>=', now()->subYear());
        }
        if (! $filtered) {
            return $query;
        }
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('store_name', 'like', '%'.$filters['q'].'%')->orWhere('reference', 'like', '%'.$filters['q'].'%'));
        }
        if (! empty($filters['zone'])) {
            $query->where('delivery_city', 'like', '%'.$filters['zone'].'%');
        }
        foreach (['status', 'urgency', 'sender_type'] as $field) {
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

        return $query;
    }
}
