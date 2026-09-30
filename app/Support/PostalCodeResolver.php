<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PostalCodeResolver
{
    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $municipalities = null;

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
        return $this->municipalities()[ShippingQuote::cityKey($city)] ?? [];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function municipalities(): array
    {
        if ($this->municipalities === null) {
            $this->municipalities = [];
            $data = json_decode(file_get_contents(resource_path('italian-postal-codes.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['municipalities'] as $place) {
                foreach ([$place['name'], ...($place['aliases'] ?? [])] as $name) {
                    $this->municipalities[ShippingQuote::cityKey($name)][] = $place;
                }
            }
        }

        return $this->municipalities;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['delivery_postal_code' => $message]);
    }
}
