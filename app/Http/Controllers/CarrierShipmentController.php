<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\OrderPrice;
use App\Support\RiderOperations;
use App\Support\RiderTracking;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CarrierShipmentController extends Controller
{
    public function update(Request $request, Order $order, NotifyOrderParticipants $notify): RedirectResponse
    {
        Gate::authorize('update', $order);
        $data = $request->validate(['version' => ['required', 'integer'], 'carrier_name' => ['nullable', 'string', 'max:100'], 'carrier_tracking' => ['required', 'string', 'max:100'], 'carrier_status' => ['required', 'in:booked,handed_over,in_transit,delivery_issue'], 'estimated_at' => ['prohibited']]);
        DB::transaction(function () use ($request, $order, $data, $notify): void {
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->is_active && $actor->isStaff() && ($actor->role === UserRole::Admin || $actor->email_verified_at !== null), 403);
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('update', $locked);
            abort_unless($locked->shipping_type === 'external' && $locked->version === (int) $data['version'], 409);
            abort_if(in_array($locked->status->value, OrderStatus::closed(), true), 409, 'La spedizione è chiusa.');
            app(OrderPrice::class)->assertApproved($locked);
            abort_if($locked->status === OrderStatus::Received, 409, 'Prendi prima in carico la spedizione.');
            $allowed = match ($locked->carrier_status) {
                null => ['booked', 'handed_over'],
                'booked' => ['booked', 'handed_over'],
                'handed_over' => ['handed_over', 'in_transit', 'delivery_issue'],
                'in_transit' => ['in_transit', 'delivery_issue'],
                'delivery_issue' => ['delivery_issue', 'in_transit'],
                default => [],
            };
            abort_unless(in_array($data['carrier_status'], $allowed, true), 409, 'Passaggio del vettore non consentito.');
            $before = $locked->only(['carrier_name', 'carrier_tracking', 'carrier_status', 'carrier_handed_at', 'estimated_at']);
            $locked->carrier_name = $data['carrier_name'] ?? $locked->carrier_name;
            abort_unless($locked->carrier_name && $locked->price_cents !== null, 409, 'Conferma prima una tariffa con vettore.');
            if ($data['carrier_status'] !== 'booked') {
                abort_unless($locked->events()->where('status', OrderStatus::PickedUp)->exists(), 409, 'Registra prima il ritiro del pacco.');
            }
            $locked->carrier_tracking = $data['carrier_tracking'];
            $locked->carrier_status = $data['carrier_status'];
            if (in_array($data['carrier_status'], ['handed_over', 'in_transit', 'delivery_issue'], true)) {
                app(RiderTracking::class)->stop($locked);
                $locked->carrier_handed_at ??= now();
                $locked->tracking_started_at ??= now();
            }
            $locked->version++;
            $locked->save();
            $labels = ['booked' => 'Spedizione prenotata presso il vettore', 'handed_over' => 'Affidata al vettore', 'in_transit' => 'In transito con il vettore', 'delivery_issue' => 'Problema di consegna del vettore'];
            $locked->events()->create(['rider_id' => $locked->rider_id, 'operational_zone' => app(RiderOperations::class)->zone($locked), 'user_id' => $request->user()->id, 'status' => $locked->status, 'public_note' => $labels[$data['carrier_status']]]);
            app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'carrier.updated', $before, $locked->only(array_keys($before)));
            $notify->handle($locked, $labels[$data['carrier_status']], $request->user()->id);
        }, 3);

        return back()->with('status', 'Dati del vettore aggiornati.');
    }
}
