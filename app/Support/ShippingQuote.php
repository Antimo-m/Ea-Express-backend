<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ShippingRate;
use Illuminate\Support\Str;

class ShippingQuote
{
    public static function cityKey(string $city): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim(Str::ascii($city))));
    }

    /** @return array<string,mixed> */
    public function find(string $city, ?string $postalCode, ?string $zone = null, ?string $street = null, bool $lock = false): array
    {
        $query = ShippingRate::where('active', true)->where('city_key', self::cityKey($city));
        $query->when($lock, fn ($q) => $q->lockForUpdate());
        $candidates = (clone $query)->get()->filter(fn ($rate) => (! $rate->zone || ($zone && self::cityKey($rate->zone) === self::cityKey($zone))) && (! $rate->street || ($street && self::cityKey($rate->street) === self::cityKey($street))));
        $query->whereIn('id', $candidates->modelKeys());
        $exact = $postalCode ? (clone $query)->where('postal_code', $postalCode)->get() : collect();
        $matches = $exact->isNotEmpty() ? $exact : (clone $query)->whereNull('postal_code')->get();
        $specificity = $matches->map(fn ($rate) => (int) (bool) $rate->zone + (int) (bool) $rate->street)->max();
        $matches = $matches->filter(fn ($rate) => (int) (bool) $rate->zone + (int) (bool) $rate->street === $specificity);
        if ($matches->count() !== 1) {
            return ['available' => false, 'price_cents' => null, 'rate_id' => null, 'reason' => $matches->count() > 1 ? 'Zona ambigua: tariffa da verificare.' : 'Tariffa da verificare. Nessuna corrispondenza univoca nel listino.'];
        }
        $rate = $matches->first();

        return ['available' => true, 'rate_id' => $rate->id, 'price_cents' => $rate->price_cents, 'city' => $rate->city, 'zone' => $rate->zone, 'street' => $rate->street, 'area' => $rate->area, 'postal_code' => $rate->postal_code, 'delivery_time' => $rate->delivery_time, 'source_reference' => $rate->source_reference, 'reason' => $rate->postal_code ? null : 'Tariffa per località; il listino non specifica un CAP.'];
    }

    public function apply(Order $order, ?array $quote = null): void
    {
        $quote ??= $this->find($order->delivery_city, $order->delivery_postal_code, $order->delivery_zone, $order->delivery_address);
        $order->pricing_version = 1;
        $order->shipping_rate_id = $quote['rate_id'];
        $order->quoted_price_cents = $quote['price_cents'];
        $order->rate_snapshot = $quote;
        $order->price_cents = null;
        $order->price_state = $quote['available'] ? 'awaiting_rider' : 'unavailable';
    }
}
