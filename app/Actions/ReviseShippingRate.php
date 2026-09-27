<?php

namespace App\Actions;

use App\Models\ShippingRate;
use App\Models\User;
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
            $data['is_default'] = $rate?->is_default ?? false;
            $data['shipping_type'] = $data['is_default'] ? 'external' : ($data['shipping_type'] ?? 'regional');
            if ($data['is_default']) {
                $data['city'] = 'Fuori regione';
                foreach (['postal_code', 'zone', 'street', 'max_weight_kg', 'max_dimension_cm'] as $criterion) {
                    $data[$criterion] = null;
                }
            }
            $data['city_key'] = ShippingQuote::cityKey($data['city']);
            foreach (['zone', 'street'] as $field) {
                $data[$field] = empty($data[$field]) ? null : ShippingQuote::cityKey($data[$field]);
            }
            $duplicates = ShippingRate::where('is_default', $data['is_default'])->where('shipping_type', $data['shipping_type'])->where('max_weight_kg', $data['max_weight_kg'] ?? null)->where('max_dimension_cm', $data['max_dimension_cm'] ?? null)->where('active', true)->where('city_key', $data['city_key'])->where('postal_code', $data['postal_code'] ?? null)->where('zone', $data['zone'])->where('street', $data['street'])->when($rate, fn ($q) => $q->whereKeyNot($rate->id));
            if (($data['active'] ?? true) && $duplicates->exists()) {
                throw ValidationException::withMessages(['city' => 'Esiste già una tariffa attiva con questi criteri. Modifica quella esistente.']);
            }
            $before = null;
            if ($rate) {
                $rate = ShippingRate::query()->lockForUpdate()->findOrFail($rate->id);
                abort_if($rate->archived_at !== null, 409, 'Questa tariffa è archiviata.');
                abort_if(ShippingRate::where('supersedes_id', $rate->id)->exists(), 409, 'Questa versione è stata sostituita. Apri la versione più recente.');
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
