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
            $data['city_key'] = ShippingQuote::cityKey($data['city']);
            foreach (['zone', 'street'] as $field) {
                $data[$field] = empty($data[$field]) ? null : ShippingQuote::cityKey($data[$field]);
            }
            $duplicates = ShippingRate::where('active', true)->where('city_key', $data['city_key'])->where('postal_code', $data['postal_code'] ?? null)->where('zone', $data['zone'])->where('street', $data['street'])->when($rate, fn ($q) => $q->whereKeyNot($rate->id));
            if (($data['active'] ?? true) && $duplicates->exists()) {
                throw ValidationException::withMessages(['city' => 'Esiste già una tariffa attiva con questi criteri. Modifica quella esistente.']);
            }
            $before = null;
            if ($rate) {
                $rate = ShippingRate::query()->lockForUpdate()->findOrFail($rate->id);
                abort_if(ShippingRate::where('supersedes_id', $rate->id)->exists(), 409, 'Questa versione è stata sostituita. Apri la versione più recente.');
                $before = $rate->toArray();
                $rate->active = false;
                $rate->save();
            }
            $data['city_key'] = ShippingQuote::cityKey($data['city']);
            $data['supersedes_id'] = $rate?->id;
            $new = ShippingRate::create($data);
            app(RecordEconomicAudit::class)->handle($user, $new, 'rate.revised', $before, $new->toArray());

            return $new;
        }, 3);
    }
}
