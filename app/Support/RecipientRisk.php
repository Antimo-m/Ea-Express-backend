<?php

namespace App\Support;

use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\Models\RecipientIncident;
use App\Models\RecipientRiskProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RecipientRisk
{
    /** @param array<string, mixed> $recipient @return array{recipient_risk_profile_id: int, phone_key: ?string, address_key: ?string, recipient: array<string, mixed>} */
    public function identity(array $recipient): array
    {
        $recipient = array_intersect_key($recipient, array_flip(RecipientIdentity::Fields));
        $keys = RecipientIdentity::keys($recipient);
        $identity = $keys['phone_key'] ?? $keys['address_key'];
        if (! $identity) {
            throw ValidationException::withMessages(['recipient_phone' => 'Servono un telefono valido o nome e indirizzo completo per registrare il precedente.']);
        }
        $existing = RecipientIncident::query()->where(function ($query) use ($keys): void {
            $query->whereRaw('1 = 0');
            foreach ($keys as $column => $value) {
                if ($value) {
                    $query->orWhere($column, $value);
                }
            }
        })->orderBy('id')->first();
        $profile = $existing?->profile ?? RecipientRiskProfile::firstOrCreate(['identity_key' => $identity]);

        return ['recipient_risk_profile_id' => $profile->id, ...$keys, 'recipient' => $recipient];
    }

    public function record(Order $order, User $actor): void
    {
        if (RecipientIncident::where('order_id', $order->id)->exists()) {
            return;
        }
        $incident = RecipientIncident::create([
            ...$this->identity($order->only(RecipientIdentity::Fields)),
            'order_id' => $order->id, 'recorded_by' => $actor->id,
            'reason' => 'recipient_absent', 'occurred_at' => now(),
        ]);
        app(RecordEconomicAudit::class)->handle($actor, $incident, 'recipient_incident.created', null, $incident->toArray());
    }

    /** @param Collection<int, Order> $orders @return array<int, array{count: int, last_at: ?string}> */
    public function forOrders(Collection $orders): array
    {
        $keys = $orders->mapWithKeys(fn (Order $order): array => [$order->id => RecipientIdentity::keys($order->only(RecipientIdentity::Fields))]);
        $phones = $keys->pluck('phone_key')->filter()->unique()->values();
        $addresses = $keys->pluck('address_key')->filter()->unique()->values();
        if ($phones->isEmpty() && $addresses->isEmpty()) {
            return [];
        }
        $groups = RecipientIncident::whereNull('dismissed_at')
            ->where(fn ($query) => $query->whereIn('phone_key', $phones)->orWhereIn('address_key', $addresses))
            ->selectRaw('phone_key, address_key, COUNT(*) AS incident_count, MAX(occurred_at) AS last_at')
            ->groupBy('phone_key', 'address_key')->get();
        $result = [];
        foreach ($keys as $id => $identity) {
            $matches = $groups->filter(fn ($group): bool => ($identity['phone_key'] && $identity['phone_key'] === $group->phone_key) || ($identity['address_key'] && $identity['address_key'] === $group->address_key));
            $result[$id] = ['count' => (int) $matches->sum('incident_count'), 'last_at' => $matches->max('last_at')];
        }

        return $result;
    }
}
