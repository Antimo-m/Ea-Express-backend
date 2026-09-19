<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutReview
{
    public function __construct(private ShippingQuote $quotes) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function preview(User $user, array $data, ?Order $order = null): array
    {
        $data = $this->normalized($data);
        $quote = $this->quote($data, $order);
        $value = Money::cents((string) ($data['parcel_value'] ?? '0'));
        $payload = ['user_id' => $user->id, 'order_id' => $order?->id, 'data' => $data, 'quote' => $quote, 'expires' => now()->addMinutes(30)->timestamp, 'key' => (string) Str::uuid()];

        return ['data' => $data, 'quote' => $quote, 'parcel_value_cents' => $value, 'shipping_price_cents' => $quote['price_cents'], 'total_cents' => $quote['available'] ? $value + $quote['price_cents'] : null, 'checkout_token' => $quote['available'] ? Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)) : null];
    }

    /** Must run within the order transaction. @param array<string,mixed> $data @return array<string,mixed> */
    public function confirm(User $user, array $data, ?Order $order = null): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($data['checkout_token'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['checkout_token' => 'Apri il riepilogo prima di confermare.']);
        }
        abort_unless($payload['user_id'] === $user->id && $payload['order_id'] === $order?->id, 403);
        abort_unless($payload['expires'] >= now()->timestamp, 409, 'Riepilogo scaduto. Rivedi la richiesta prima di confermare.');
        abort_unless($payload['data'] === $this->normalized($data), 409, 'I dati sono cambiati. Apri un nuovo riepilogo.');
        if (! $order && Order::where('checkout_key', $payload['key'])->where('customer_id', $user->id)->exists()) {
            return $payload;
        }
        ShippingRate::whereKey($payload['quote']['rate_id'])->lockForUpdate()->first();
        $current = $this->quote($data, $order, true);
        abort_unless($current['available'] && $current['rate_id'] === $payload['quote']['rate_id'] && $current['price_cents'] === $payload['quote']['price_cents'], 409, 'Il listino è cambiato. Torna ai dati e verifica il nuovo riepilogo.');

        return $payload;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalized(array $data): array
    {
        unset($data['checkout_token']);
        ksort($data);

        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function quote(array $data, ?Order $order, bool $lock = false): array
    {
        if ($order && $order->price_cents !== null && collect(['delivery_city', 'delivery_postal_code', 'delivery_zone', 'delivery_address'])->every(fn ($field) => ($data[$field] ?? null) === ($order->{$field} ?? null))) {
            return [...($order->rate_snapshot ?? []), 'available' => true, 'rate_id' => $order->shipping_rate_id, 'price_cents' => $order->price_cents, 'reason' => 'Prezzo già salvato per questa spedizione.'];
        }

        return $this->quotes->find($data['delivery_city'], $data['delivery_postal_code'] ?? null, $data['delivery_zone'] ?? null, $data['delivery_address'] ?? null, $lock);
    }
}
