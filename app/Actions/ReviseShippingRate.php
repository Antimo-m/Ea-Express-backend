<?php

namespace App\Actions;

use App\Models\ShippingRate;
use App\Models\User;
use App\Support\PostalCodeResolver;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviseShippingRate
{
    /** @param array<string,mixed> $data */
    public function handle(User $user, array $data, ?ShippingRate $rate = null): ShippingRate
    {
        return DB::transaction(function () use ($user, $data, $rate): ShippingRate {
            User::where('role', UserRole::Admin)->orderBy('id')->lockForUpdate()->firstOrFail();
            $postalErrorField = ! empty($data['postal_code']) ? 'postal_code' : 'postal_codes';
            if ($rate) {
                $rate = ShippingRate::query()->lockForUpdate()->findOrFail($rate->id);
                abort_if($rate->archived_at !== null, 409, 'Questa tariffa è archiviata.');
                abort_if(ShippingRate::where('supersedes_id', $rate->id)->exists(), 409, 'Questa versione è stata sostituita. Apri la versione più recente.');
            }
            if (array_key_exists('postal_codes', $data)) {
                $codes = $data['postal_codes'] ?? [];
                if (! empty($data['postal_code'])) {
                    if ($codes !== [] && $codes !== [$data['postal_code']]) {
                        throw ValidationException::withMessages(['postal_codes' => 'Il CAP singolo e l’elenco dei CAP non coincidono. Correggi la selezione.']);
                    }
                    $codes = [$data['postal_code']];
                }
                $data['postal_codes'] = $codes;
                $data['postal_code'] = count($codes) === 1 ? $codes[0] : null;
            } elseif (array_key_exists('postal_code', $data)) {
                $data['postal_codes'] = ! empty($data['postal_code']) ? [$data['postal_code']] : [];
            }
            $territoryChanged = ! $rate || ($data['city'] ?? $rate->city) !== $rate->city
                || (array_key_exists('postal_code', $data) && $data['postal_code'] !== $rate->postal_code)
                || (array_key_exists('postal_codes', $data) && $data['postal_codes'] !== $rate->postal_codes);
            $data = [...($rate?->only($rate->getFillable()) ?? []), ...$data];
            $data['source_reference'] ??= 'Inserimento dal gestionale';
            $data['is_default'] = $rate?->is_default ?? false;
            $data['shipping_type'] = $data['is_default'] ? 'external' : ($data['shipping_type'] ?? 'regional');
            if ($data['is_default']) {
                $data['city'] = 'Fuori regione';
                foreach (['postal_code', 'postal_codes', 'zone', 'street', 'max_weight_kg', 'max_dimension_cm'] as $criterion) {
                    $data[$criterion] = null;
                }
            }
            $data['city_key'] = ShippingQuote::cityKey($data['city']);
            if (! $data['is_default'] && ($territoryChanged || (($data['active'] ?? true) && ($rate?->territory_review_required || ! $rate?->active)))) {
                $places = app(PostalCodeResolver::class)->places($data['city']);
                $selectedCodes = ($data['postal_codes'] ?? []) ?: (! empty($data['postal_code']) ? [$data['postal_code']] : []);
                if (count($places) > 1 && $selectedCodes !== []) {
                    $places = array_values(array_filter($places, fn (array $place): bool => array_diff($selectedCodes, $place['postal_codes']) === []));
                }
                if (count($places) === 1) {
                    $codes = $places[0]['postal_codes'];
                    if (! empty($data['postal_code']) && ! in_array($data['postal_code'], $codes, true)) {
                        throw ValidationException::withMessages([$postalErrorField => 'Il CAP non corrisponde alla località del listino.']);
                    }
                    $data['city'] = $places[0]['name'];
                    $data['city_key'] = ShippingQuote::cityKey($data['city']);
                    $selectedCodes = ! empty($data['postal_code']) ? [$data['postal_code']] : ($data['postal_codes'] ?? $codes);
                    if (array_diff($selectedCodes, $codes)) {
                        throw ValidationException::withMessages(['postal_codes' => 'Uno o più CAP non corrispondono alla località del listino.']);
                    }
                    $data['postal_codes'] = array_values($selectedCodes ?: $codes);
                    $data['postal_code'] = count($data['postal_codes']) === 1 ? $data['postal_codes'][0] : null;
                    $data['territory_review_required'] = false;
                } else {
                    throw ValidationException::withMessages(['city' => 'Comune non identificato con certezza. Usa il nome ufficiale e, per i comuni omonimi, aggiungi la sigla della provincia tra parentesi (es. Samone (TO)).']);
                }
            }
            foreach (['zone', 'street'] as $field) {
                $data[$field] = empty($data[$field]) ? null : ShippingQuote::cityKey($data[$field]);
            }
            $duplicates = ShippingRate::where('is_default', $data['is_default'])->where('shipping_type', $data['shipping_type'])->where('max_weight_kg', $data['max_weight_kg'] ?? null)->where('max_dimension_cm', $data['max_dimension_cm'] ?? null)->where('active', true)->where('city_key', $data['city_key'])->where('zone', $data['zone'])->where('street', $data['street'])->when($rate, fn ($q) => $q->whereKeyNot($rate->id));
            if (($data['active'] ?? true) && $duplicates->get()->contains(function (ShippingRate $candidate) use ($data): bool {
                $existingCodes = $candidate->postal_codes ?: ($candidate->postal_code ? [$candidate->postal_code] : []);
                $newCodes = $data['postal_codes'] ?? (! empty($data['postal_code']) ? [$data['postal_code']] : []);

                return $existingCodes === [] || $newCodes === [] || array_intersect($existingCodes, $newCodes) !== [];
            })) {
                throw ValidationException::withMessages(['city' => 'Esiste già una tariffa attiva con questi criteri. Modifica quella esistente.']);
            }
            $before = null;
            if ($rate) {
                $before = $rate->toArray();
                $rate->active = false;
                $rate->save();
            }
            $data['shipping_type'] ??= 'regional';
            $data['city_key'] = ShippingQuote::cityKey($data['city']);
            $data['supersedes_id'] = $rate?->id;
            $new = ShippingRate::create($data);
            app(RecordEconomicAudit::class)->handle($user, $new, 'rate.revised', $before, $new->toArray());

            return $new;
        }, 3);
    }
}
