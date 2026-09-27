<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\BookingRules;
use App\Support\ShippingQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReschedulePickup
{
    public function __construct(private NotifyOrderParticipants $notify, private BookingRules $booking) {}

    /** @param array{pickup_date: string, pickup_from: string, pickup_to: string, reason: string, version: int|string} $data */
    public function handle(User $actor, Order $order, array $data): void
    {
        DB::transaction(function () use ($actor, $order, $data): void {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('update', $locked);
            abort_unless($locked->version === (int) $data['version'] && Order::awaitingPickup()->whereKey($locked->id)->exists(), 409, 'Il ritiro è stato aggiornato, completato o chiuso. Ricarica la richiesta.');
            $this->booking->validate($data);
            abort_if($this->booking->unchanged($data, $locked), 422, 'La nuova data e fascia coincidono con quelle già registrate.');
            $before = $locked->pickupSchedule();
            $locked->fill(collect($data)->only(['pickup_date', 'pickup_from', 'pickup_to'])->all());
            $locked->pickup_reminded_on = null;
            if ($locked->status !== OrderStatus::Received) {
                $locked->status = OrderStatus::PickupScheduled;
            }
            app(ShippingQuote::class)->estimate($locked);
            $locked->version++;
            $locked->save();
            $after = $locked->pickupSchedule();
            $message = 'Ritiro ripianificato al '.$locked->pickup_date->format('d/m/Y').' · '.$after['pickup_from'].'–'.$after['pickup_to'].'.';
            $locked->events()->create(['user_id' => $actor->id, 'status' => $locked->status, 'note' => $data['reason'], 'public_note' => $message, 'schedule_change' => compact('before', 'after')]);
            $this->notify->handle($locked, $message, $actor->id);
        }, 3);
    }
}
