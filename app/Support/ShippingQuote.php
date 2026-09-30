<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ShippingRate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShippingQuote
{
    public static function cityKey(string $city): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim(str_replace(["'", '’', '`', '-'], ' ', Str::ascii($city)))));
    }

    /** @return array<string,mixed> */
    public function find(string $city, ?string $postalCode, ?string $zone = null, ?string $street = null, bool $lock = false, string $shippingType = 'regional', ?float $weight = null, ?float $dimension = null, ?string $province = null, ?string $region = null): array
    {
        try {
            $place = app(PostalCodeResolver::class)->canonical($city, $postalCode, $province, $region);
            $postalCode = $place['postal_code'];
        } catch (ValidationException $exception) {
            return ['available' => false, 'price_cents' => null, 'rate_id' => null, 'territory_valid' => false, 'errors' => $exception->errors(), 'reason' => $exception->errors()['delivery_postal_code'][0]];
        }
        $cityKey = self::cityKey($place['name']);
        $missingMeasurements = $shippingType === 'external' && ($weight === null || $dimension === null);
        $rates = ShippingRate::where('shipping_type', $shippingType)->whereNull('archived_at')->where('active', true)->where('territory_review_required', false)
            ->where(fn ($rates) => $rates->where('city_key', $cityKey)->when($shippingType === 'external', fn ($rates) => $rates->orWhere('is_default', true)))
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->filter(fn ($rate) => (! $rate->postal_code || $rate->postal_code === $postalCode)
                && (! $rate->postal_codes || in_array($postalCode, $rate->postal_codes, true)));
        if ($rates->contains(fn ($rate) => ! $rate->is_default)) {
            $rates = $rates->reject(fn ($rate) => $rate->is_default);
        }
        $unavailable = fn (string $reason): array => ['available' => false, 'price_cents' => null, 'rate_id' => null, 'territory_valid' => true, 'postal_code' => $postalCode, 'reason' => $reason];
        $streets = $rates->filter(fn ($rate) => (bool) $rate->street);
        if ($streets->isNotEmpty()) {
            $rates = $streets->filter(fn ($rate) => $street && self::streetKey($rate->street) === self::streetKey($street));
            if ($rates->isEmpty()) {
                return $unavailable('Indirizzo non verificabile rispetto alle tariffe specifiche. Contatta il servizio clienti.');
            }
        } else {
            $zones = $rates->filter(fn ($rate) => (bool) $rate->zone);
            if ($zones->isNotEmpty()) {
                if ($zones->contains(fn ($rate) => ! $rate->postal_code && ! $rate->postal_codes)
                    || $zones->map(fn ($rate) => self::cityKey($rate->zone))->unique()->count() !== 1) {
                    return $unavailable('Zona non verificabile dal CAP. Contatta il servizio clienti.');
                }
                $rates = $zones;
            }
        }
        $specificity = fn ($rate): int => $rate->postal_code ? 2 : ($rate->postal_codes ? 1 : 0);
        $bestSpecificity = $rates->map($specificity)->max();
        $rates = $rates->filter(fn ($rate) => $specificity($rate) === $bestSpecificity);
        if ($missingMeasurements && ($rates->isEmpty() || $rates->contains(fn ($rate) => ! $rate->is_default || $rate->max_weight_kg !== null || $rate->max_dimension_cm !== null))) {
            return $unavailable('Inserisci peso e dimensioni per verificare la tariffa fuori regione.');
        }
        $matches = $rates->filter(fn ($rate) => ($rate->max_weight_kg === null || ($weight !== null && $weight > 0 && $weight <= (float) $rate->max_weight_kg))
            && ($rate->max_dimension_cm === null || ($dimension !== null && $dimension > 0 && $dimension <= (float) $rate->max_dimension_cm)))
            ->sortBy(fn ($rate) => [(float) ($rate->max_weight_kg ?? INF), (float) ($rate->max_dimension_cm ?? INF)]);
        if ($matches->isNotEmpty()) {
            $best = $matches->first();
            $matches = $matches->filter(fn ($rate) => $rate->max_weight_kg === $best->max_weight_kg && $rate->max_dimension_cm === $best->max_dimension_cm);
        }
        if ($matches->count() !== 1) {
            return $unavailable($matches->count() > 1 ? 'Zona ambigua: tariffa da verificare.' : 'Tariffa da verificare. Nessuna corrispondenza compatibile nel listino.');
        }
        $rate = $matches->first();

        return ['territory_valid' => true, 'shipping_type' => $rate->shipping_type, 'carrier_name' => $rate->carrier_name, 'carrier_cost_cents' => $rate->carrier_cost_cents, 'delivery_days_min' => $rate->delivery_days_min, 'delivery_days_max' => $rate->delivery_days_max, 'available' => true, 'rate_id' => $rate->id, 'price_cents' => $rate->price_cents, 'city' => $rate->city, 'zone' => $rate->zone, 'street' => $rate->street, 'area' => $rate->area, 'postal_code' => $postalCode, 'delivery_time' => $rate->delivery_time, 'source_reference' => $rate->source_reference, 'reason' => $rate->is_default ? 'Tariffa generale fuori regione.' : ($rate->postal_code || $rate->postal_codes ? null : 'Tariffa per località; il listino non specifica un CAP.')];
    }

    public static function streetKey(string $street): string
    {
        return self::cityKey(preg_replace('/[.,]/u', ' ', $street));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function measurements(array $data): array
    {
        if (! empty($data['packages'])) {
            $data['weight_kg'] = number_format(ceil(array_sum(array_column($data['packages'], 'weight_kg')) * 100 - 0.000001) / 100, 2, '.', '');
            $data['max_dimension_cm'] = number_format(ceil(max(array_map(fn ($package) => max($package['length_cm'], $package['width_cm'], $package['height_cm']), $data['packages'])) * 100 - 0.000001) / 100, 2, '.', '');
        }

        return $data;
    }

    public function apply(Order $order, ?array $quote = null): void
    {
        $quote ??= $this->find($order->delivery_city, $order->delivery_postal_code, $order->delivery_zone, $order->delivery_address, $order->getConnection()->transactionLevel() > 0, $order->shipping_type, $order->weight_kg === null ? null : (float) $order->weight_kg, $order->max_dimension_cm === null ? null : (float) $order->max_dimension_cm, $order->delivery_province, $order->delivery_region);
        $order->carrier_cost_cents = $quote['carrier_cost_cents'] ?? 0;
        $order->carrier_name = $quote['carrier_name'] ?? null;
        $order->pricing_version = 1;
        $order->shipping_rate_id = $quote['rate_id'];
        $order->quoted_price_cents = $quote['price_cents'];
        $order->rate_snapshot = $quote;
        $this->estimate($order);
        $order->price_cents = null;
        $order->price_state = $quote['available'] ? 'awaiting_rider' : 'unavailable';
    }

    /** Only customer-facing tariff fields may leave the backend. @return array<string,mixed> */
    public static function customerData(?array $quote): ?array
    {
        return $quote === null ? null : array_intersect_key($quote, array_flip(['available', 'rate_id', 'price_cents', 'city', 'zone', 'street', 'area', 'postal_code', 'delivery_time', 'reason', 'shipping_type', 'territory_valid', 'errors', 'delivery_days_min', 'delivery_days_max']));
    }

    public function estimate(Order $order): void
    {
        $quote = $order->rate_snapshot ?? [];
        $order->estimated_delivery_from = ! empty($quote['delivery_days_min']) ? $order->pickup_date->copy()->addWeekdays($quote['delivery_days_min']) : null;
        $order->estimated_delivery_to = ! empty($quote['delivery_days_max']) ? $order->pickup_date->copy()->addWeekdays($quote['delivery_days_max']) : null;
    }
}
