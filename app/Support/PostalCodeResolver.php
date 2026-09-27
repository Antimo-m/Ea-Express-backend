<?php

namespace App\Support;

use App\Models\ShippingRate;
use Illuminate\Validation\ValidationException;

class PostalCodeResolver
{
    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $municipalities = null;

    public function resolve(string $city, ?string $postalCode, ?string $zone = null, ?string $street = null, string $shippingType = 'regional', ?string $province = null, ?string $region = null): string
    {
        $key = ShippingQuote::cityKey($city);
        $knownPlaces = $this->municipalities()[$key] ?? [];
        $places = array_values(array_filter($knownPlaces, fn ($place) => (! $province || in_array(ShippingQuote::cityKey($province), [ShippingQuote::cityKey($place['province']), ShippingQuote::cityKey($place['province_code'] ?? '')], true)) && (! $region || ShippingQuote::cityKey($region) === ShippingQuote::cityKey($place['region']))));
        if (count($places) > 1) {
            $this->invalid('Comune omonimo: specifica provincia e regione per verificare il CAP.');
        }
        if ($knownPlaces !== [] && $places === []) {
            $this->invalid('Provincia o regione non corrispondono al comune indicato.');
        }
        $rates = ShippingRate::where('city_key', $key)->where('shipping_type', $shippingType)->where('active', true)->whereNull('archived_at')->where('is_default', false)->get()
            ->filter(fn ($rate) => (! $rate->zone || ($zone && ShippingQuote::cityKey($rate->zone) === ShippingQuote::cityKey($zone))) && (! $rate->street || ($street && ShippingQuote::cityKey($rate->street) === ShippingQuote::cityKey($street))));
        $specificity = $rates->map(fn ($rate) => (int) (bool) $rate->zone + (int) (bool) $rate->street)->max();
        $rates = $rates->filter(fn ($rate) => (int) (bool) $rate->zone + (int) (bool) $rate->street === $specificity);
        $codes = $rates->pluck('postal_code')->filter()->unique()->values();
        $postalCode = trim($postalCode ?? '');

        if ($codes->isNotEmpty() && ! $rates->contains(fn ($rate) => ! $rate->postal_code)) {
            if ($postalCode !== '' && $codes->containsStrict($postalCode)) {
                return $postalCode;
            }
            if ($postalCode === '' && $codes->count() === 1) {
                return $codes->first();
            }
            $this->invalid($postalCode === '' ? 'La località ha più CAP. Specifica il CAP della consegna o la zona.' : 'Il CAP non corrisponde alla località e alla zona del listino.');
        }

        $matches = $places;
        if (count($matches) !== 1) {
            $this->invalid(count($matches) > 1 ? 'Comune omonimo: specifica provincia e regione per verificare il CAP.' : 'Località non trovata: verifica comune, provincia e regione. Il CAP non può essere verificato.');
        }
        $codes = $matches[0]['postal_codes'];
        if ($postalCode !== '' && in_array($postalCode, $codes, true)) {
            return $postalCode;
        }
        if ($postalCode !== '') {
            $this->invalid('Il CAP non corrisponde al comune indicato.');
        }
        if (count($codes) === 1) {
            return $codes[0];
        }
        $this->invalid(count($codes) > 1 ? 'Il comune ha più CAP. Specifica il CAP della consegna; non è possibile sceglierlo automaticamente.' : 'Nessun CAP verificabile per questa località.');
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function municipalities(): array
    {
        if ($this->municipalities === null) {
            $this->municipalities = [];
            $data = json_decode(file_get_contents(resource_path('italian-postal-codes.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['municipalities'] as $place) {
                $this->municipalities[ShippingQuote::cityKey($place['name'])][] = $place;
            }
        }

        return $this->municipalities;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['delivery_postal_code' => $message]);
    }
}
