<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PostalCodeResolver
{
    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $municipalities = null;

    public function __construct(private PostalCodeIndex $index) {}

    public function resolve(string $city, ?string $postalCode, ?string $zone = null, ?string $street = null, string $shippingType = 'regional', ?string $province = null, ?string $region = null): string
    {
        return $this->canonical($city, $postalCode, $province, $region)['postal_code'];
    }

    /** @return array{name: string, province: string, province_code: string, region: string, postal_codes: list<string>, postal_code: string} */
    public function canonical(string $city, ?string $postalCode, ?string $province = null, ?string $region = null): array
    {
        if (preg_match('/^(.*?)\s*\(([A-Za-z]{2})\)\s*$/u', trim($city), $suffix)) {
            $city = $suffix[1];
            if ($province && ShippingQuote::cityKey($province) !== ShippingQuote::cityKey($suffix[2])) {
                $known = $this->municipalities()[ShippingQuote::cityKey($city)] ?? [];
                if (! collect($known)->contains(fn ($place) => strcasecmp($place['province_code'], $suffix[2]) === 0 && ShippingQuote::cityKey($place['province']) === ShippingQuote::cityKey($province))) {
                    $this->invalid('Provincia o regione non corrispondono al comune indicato.');
                }
            }
            $province = $suffix[2];
        }
        $key = ShippingQuote::cityKey($city);
        $knownPlaces = $this->municipalities()[$key] ?? [];
        $places = array_values(array_filter($knownPlaces, fn ($place) => (! $province || in_array(ShippingQuote::cityKey($province), [ShippingQuote::cityKey($place['province']), ShippingQuote::cityKey($place['province_code'] ?? '')], true)) && (! $region || ShippingQuote::cityKey($region) === ShippingQuote::cityKey($place['region']))));
        if (count($places) > 1) {
            $this->invalid('Comune omonimo: specifica provincia e regione per verificare il CAP.');
        }
        if ($knownPlaces !== [] && $places === []) {
            $this->invalid('Provincia o regione non corrispondono al comune indicato.');
        }
        $postalCode = trim($postalCode ?? '');

        $matches = $places;
        if (count($matches) !== 1) {
            $this->invalid(count($matches) > 1 ? 'Comune omonimo: specifica provincia e regione per verificare il CAP.' : 'Località non trovata: verifica comune, provincia e regione. Il CAP non può essere verificato.');
        }
        $codes = $matches[0]['postal_codes'];
        if ($postalCode !== '' && in_array($postalCode, $codes, true)) {
            return [...$matches[0], 'postal_code' => $postalCode];
        }
        if ($postalCode !== '') {
            $this->invalid('Il CAP inserito non corrisponde al Comune di consegna selezionato. Verifica il CAP e riprova.');
        }
        if (count($codes) === 1) {
            return [...$matches[0], 'postal_code' => $codes[0]];
        }
        $this->invalid(count($codes) > 1 ? 'Il comune ha più CAP. Specifica il CAP della consegna; non è possibile sceglierlo automaticamente.' : 'Nessun CAP verificabile per questa località.');
    }

    /** @return list<array<string, mixed>> */
    public function places(string $city): array
    {
        if (preg_match('/^(.*?)\s*\(([A-Za-z]{2})\)\s*$/u', trim($city), $suffix)) {
            return array_values(array_filter($this->municipalities()[ShippingQuote::cityKey($suffix[1])] ?? [],
                fn (array $place): bool => strcasecmp($place['province_code'], $suffix[2]) === 0));
        }

        return $this->municipalities()[ShippingQuote::cityKey($city)] ?? [];
    }

    /**
     * Resolves an abbreviated municipality name only when the postal codes identify exactly one
     * municipality whose official name starts with the given words (e.g. «Casalnuovo» + 80013).
     *
     * @param  list<string>  $postalCodes
     * @return list<array<string, mixed>>
     */
    public function placesByPostalCode(string $city, array $postalCodes): array
    {
        $prefix = ShippingQuote::cityKey($city);
        if ($prefix === '' || $postalCodes === []) {
            return [];
        }
        $matches = [];
        foreach ($this->municipalities() as $key => $places) {
            if ($key !== $prefix && ! str_starts_with($key, $prefix.' ')) {
                continue;
            }
            foreach ($places as $place) {
                if (array_diff($postalCodes, $place['postal_codes']) === []) {
                    $matches[$place['name'].'|'.$place['province_code']] = $place;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * Human readable hints for an unresolved name or a postal code belonging to another municipality.
     *
     * @param  list<string>  $postalCodes
     */
    public function hint(string $city, array $postalCodes): string
    {
        $prefix = ShippingQuote::cityKey($city);
        $named = [];
        foreach ($this->municipalities() as $key => $places) {
            if ($prefix !== '' && ($key === $prefix || str_starts_with($key, $prefix.' '))) {
                foreach ($places as $place) {
                    $named[$place['name'].$place['province_code']] = $place['name'].' ('.$place['province_code'].', CAP '.implode(', ', array_slice($place['postal_codes'], 0, 3)).')';
                }
            }
        }
        $owners = [];
        foreach ($postalCodes as $code) {
            foreach ($this->municipalities() as $places) {
                foreach ($places as $place) {
                    if (in_array($code, $place['postal_codes'], true)) {
                        $owners[$place['name'].$place['province_code']] = 'il CAP '.$code.' appartiene a '.$place['name'].' ('.$place['province_code'].')';
                    }
                }
            }
        }
        $parts = [];
        if ($named) {
            $parts[] = 'Comuni corrispondenti: '.implode('; ', array_slice(array_values($named), 0, 4)).'.';
        }
        if ($owners) {
            $parts[] = ucfirst(implode('; ', array_slice(array_values($owners), 0, 3))).'.';
        }

        return implode(' ', $parts);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function municipalities(): array
    {
        return $this->municipalities ??= $this->index->load();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['delivery_postal_code' => $message]);
    }
}
