<?php

namespace App\Support;

use App\Models\Order;

class OrderPrice
{
    /** The caller holds the order lock before checking proposal state. */
    public function assertApproved(Order $order, bool $acceptingQuote = false, bool $allowUnpricedLegacy = false): void
    {
        $proposal = $order->priceProposals()->latest('id')->first();
        abort_if(in_array($order->price_state, ['awaiting_customer', 'rejected'], true)
            || $order->priceProposals()->where('state', 'pending')->exists()
            || ($proposal && $proposal->state !== 'accepted'), 409, 'Serve una proposta prezzo accettata dal cliente.');
        if ($proposal) {
            abort_unless($order->price_state === 'agreed' && $order->price_cents === $proposal->price_cents, 409, 'Il prezzo non coincide con la proposta accettata.');
        }
        $canConfirmQuote = $acceptingQuote && ! $proposal && $order->price_state === 'awaiting_rider' && $order->quoted_price_cents !== null;
        if ($order->pricing_version === 1) {
            abort_unless($canConfirmQuote || ($order->price_state === 'agreed' && $order->price_cents !== null), 409, 'Concorda prima la tariffa con il cliente.');
        }
        if ($allowUnpricedLegacy && $order->pricing_version !== 1 && $order->price_cents === null) {
            return;
        }
        abort_unless(($canConfirmQuote ? $order->quoted_price_cents : $order->price_cents) !== null
            && ($canConfirmQuote ? $order->quoted_price_cents : $order->price_cents) >= 0, 409, 'Prezzo della spedizione non valido.');
        if ($order->shipping_type === 'external') {
            abort_unless($order->carrier_cost_cents !== null && $order->carrier_cost_cents >= 0
                && ($order->pricing_version !== 1 || $order->quoted_price_cents !== null), 409, 'Dati economici del vettore non validi.');
        }
    }
}
