<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionOrder
{
    public function __construct(private NotifyOrderParticipants $notify) {}

    /** @param array<string, mixed> $data */
    public function handle(Order $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($user)->authorize('update', $locked);
            $next = OrderStatus::from($data['status']);
            if ($locked->version !== (int) $data['version'] || ! in_array($next, $locked->status->next(), true)) {
                throw ValidationException::withMessages(['status' => 'L’ordine è cambiato o il passaggio non è consentito. Ricarica la pagina.']);
            }
            if ($locked->status === OrderStatus::Rejected && ! $locked->recoverable()) {
                throw ValidationException::withMessages(['status' => 'Il recupero è consentito soltanto entro un’ora dal rifiuto.']);
            }
            if ($next === OrderStatus::Accepted) {
                $locked->rider_id = $user->id;
                $locked->price_cents = Money::cents($data['price']);
            }
            if ($next === OrderStatus::Rejected) {
                $locked->rejected_at = now();
                $locked->rejected_by = $user->id;
            }
            if ($next === OrderStatus::Received) {
                $locked->rejected_at = null;
                $locked->rejected_by = null;
            }
            if ($next === OrderStatus::RiderArriving && ! $locked->tracking_started_at) {
                $locked->tracking_started_at = now();
            }
            if ($next === OrderStatus::Delivered) {
                $locked->delivered_at = now();
            }
            if (! empty($data['estimated_at'])) {
                $locked->estimated_at = Carbon::parse($data['estimated_at'], 'Europe/Rome')->utc();
            }
            $locked->status = $next;
            $locked->version++;
            $locked->save();
            $locked->events()->create(['user_id' => $user->id, 'status' => $next, 'note' => $data['note'] ?? null, 'public_note' => $data['public_note'] ?? null]);
            $this->notify->handle($locked, $next->label(), $user->id);
        }, 3);
    }
}
