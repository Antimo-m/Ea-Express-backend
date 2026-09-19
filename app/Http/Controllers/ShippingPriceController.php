<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\OrderStatus;
use App\Support\Money;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ShippingPriceController extends Controller
{
    public function update(Request $request, Order $order, NotifyOrderParticipants $notify): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $customer = $user->role === UserRole::Customer;
        if ($customer) {
            abort_unless($order->customer_id === $user->id, 404);
        } else {
            Gate::authorize('update', $order);
        }
        $data = $request->validate(['action' => ['required', 'in:propose,accept,reject'], 'version' => ['required', 'integer', 'min:1'], 'price' => ['required_if:action,propose', 'nullable', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'reason' => ['required_if:action,propose', 'nullable', 'string', 'max:1000'], 'response_note' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($order, $user, $customer, $data, $notify): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($customer) {
                abort_unless($locked->customer_id === $user->id, 404);
            } else {
                Gate::forUser($user)->authorize('update', $locked);
            }
            abort_unless($locked->pricing_version === 1 && $locked->status === OrderStatus::Received && ! $locked->paid_at && $locked->version === (int) $data['version'], 409, 'Ordine aggiornato o prezzo non modificabile.');
            $before = ['price_cents' => $locked->price_cents, 'price_state' => $locked->price_state];
            if ($data['action'] === 'propose') {
                abort_if($customer, 403);
                abort_if($locked->price_state === 'awaiting_customer', 409, 'Attendi la risposta alla proposta aperta.');
                $proposal = $locked->priceProposals()->create(['proposed_by' => $user->id, 'previous_price_cents' => $locked->quoted_price_cents, 'price_cents' => Money::cents($data['price']), 'reason' => $data['reason'], 'state' => 'pending']);
                $locked->rider_id = $locked->rider_id ?? $user->id;
                if (! $locked->checkout_key) {
                    $locked->price_cents = null;
                }
                $locked->price_state = 'awaiting_customer';
                $title = 'Nuova proposta prezzo: '.Money::format($proposal->price_cents).' — '.$proposal->reason;
            } else {
                abort_unless($customer || ($user->role === UserRole::Admin && $locked->customer_id === null && ! empty($data['response_note'])), 403, 'Serve la risposta del cliente; per clienti senza account l’amministratore deve annotare l’accordo.');
                $proposal = $locked->priceProposals()->where('state', 'pending')->lockForUpdate()->first();
                abort_unless($proposal && $locked->price_state === 'awaiting_customer', 409, 'Nessuna proposta in attesa.');
                $accepted = $data['action'] === 'accept';
                $proposal->state = $accepted ? 'accepted' : 'rejected';
                $proposal->responded_by = $user->id;
                $proposal->responded_at = now();
                $proposal->response_note = $data['response_note'] ?? null;
                $proposal->save();
                $locked->price_cents = $accepted ? $proposal->price_cents : $locked->price_cents;
                $locked->price_state = $accepted ? 'agreed' : 'rejected';
                $title = $accepted ? 'Proposta prezzo accettata' : 'Proposta prezzo rifiutata';
            }
            $locked->version++;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($user, $proposal, 'price.'.$data['action'], $before, $proposal->toArray());
            $locked->events()->create(['user_id' => $user->id, 'status' => $locked->status, 'public_note' => $title]);
            $notify->handle($locked, $title, $user->id);
        }, 3);

        return $request->expectsJson() ? response()->json(['message' => 'Prezzo aggiornato.']) : redirect()->route('orders.show', $order)->with('status', 'Prezzo aggiornato.');
    }
}
