<?php

namespace App\Support;

use App\Models\User;

/**
 * Dati e collegamenti dei Rider per il riepilogo operativo, calcolati una sola volta per Rider e non per ordine.
 */
class RiderLinks
{
    /** @var array<int, array{slug: string, name: string, entry: array<string, string>, url: string}|null> */
    private array $riders = [];

    /** @param array<string, string> $periodParameters */
    public function __construct(private array $periodParameters) {}

    /** @param list<int> $riderIds */
    public function load(array $riderIds): void
    {
        $missing = array_values(array_diff($riderIds, array_keys($this->riders)));
        if ($missing === []) {
            return;
        }
        $users = User::whereIn('id', $missing)->get(['id', 'slug', 'name', 'is_active', 'closed_at'])->keyBy('id');
        foreach ($missing as $riderId) {
            $user = $users->get($riderId);
            if (! $user) {
                $this->riders[$riderId] = null;

                continue;
            }
            $url = route('rider-operations.show', ['rider' => $user->slug, ...$this->periodParameters]);
            $this->riders[$riderId] = ['slug' => $user->slug, 'name' => $user->name, 'url' => $url,
                'entry' => ['slug' => $user->slug, 'name' => $user->name.($user->closed_at ? ' (account eliminato)' : ($user->is_active ? '' : ' (inattivo)')), 'url' => $url]];
        }
    }

    /** @return array{slug: string, name: string, entry: array<string, string>, url: string}|null */
    public function rider(int $riderId): ?array
    {
        return $this->riders[$riderId] ?? null;
    }
}
