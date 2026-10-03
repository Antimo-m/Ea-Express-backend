<?php

namespace App\Support;

use App\Events\RiderLocationUpdated;
use App\Models\Order;
use App\Models\RiderGpsSample;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiderTracking
{
    public const ACTIVE_STATUSES = ['rider_arriving', 'picked_up', 'in_transit', 'out_for_delivery'];

    public function cache(): Repository
    {
        return Cache::store(config('tracking.cache_store'));
    }

    public function eligible(Order $order): bool
    {
        return $order->rider_id !== null && in_array($order->status->value, self::ACTIVE_STATUSES, true)
            && $order->carrier_handed_at === null;
    }

    public function authorizeRead(User $user, Order $order): void
    {
        abort_unless($user->is_active && match ($user->role) {
            UserRole::Admin => true,
            UserRole::Customer => $order->customer_id === $user->id,
            UserRole::Rider => $order->rider_id === $user->id && $user->email_verified_at !== null,
        }, 404);
    }

    public function authorizeRider(User $user, Order $order): void
    {
        abort_unless($user->canOperateDeliveries() && $order->rider_id === $user->id, 404);
    }

    /** @return array<string, mixed> */
    public function snapshot(Order $order, ?array $cachedPositions = null): array
    {
        $order->loadMissing('rider:id,name,role,is_active,email_verified_at');
        $eligible = $this->eligible($order) && $order->rider?->canOperateDeliveries();
        $location = $eligible && $order->gps_session_id ? $this->position($order->gps_session_id, $cachedPositions) : null;
        $state = match (true) {
            in_array($order->status->value, OrderStatus::closed(), true) => 'completed',
            $order->rider_id === null => 'unassigned',
            $order->carrier_handed_at !== null => 'carrier',
            ! $eligible => 'waiting',
            ! $order->gps_session_id => 'ready',
            ! $location => 'locating',
            now()->timestamp - $location['received_at_unix'] > 90 => 'stale',
            default => 'live',
        };
        if ($location) {
            unset($location['received_at_unix']);
        }

        return [
            'order_id' => $order->id, 'reference' => $order->reference,
            'status' => $order->status->value, 'status_label' => $order->status->label(),
            'state' => $state, 'eligible' => (bool) $eligible,
            'rider' => $order->rider ? ['name' => $order->rider->name] : null,
            'location' => $location,
            'pickup' => ['address' => trim($order->pickup_address.' '.$order->pickup_street_number).', '.$order->pickup_city, 'point' => $order->pickup_point],
            'delivery' => ['address' => trim($order->delivery_address.' '.$order->delivery_street_number).', '.$order->delivery_city, 'point' => $order->delivery_point],
            'map' => ['tiles' => config('tracking.tiles_url'), 'attribution' => config('tracking.tiles_attribution')],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function snapshots(Collection $orders): Collection
    {
        $keys = $orders->pluck('gps_session_id')->filter()->map(fn (string $session): string => 'gps:'.$session)->values()->all();
        $positions = $keys ? $this->cache()->many($keys) : [];

        return $orders->map(fn (Order $order): array => $this->snapshot($order, $positions));
    }

    public function stop(Order $order): void
    {
        $session = $order->gps_session_id;
        if (! $session) {
            return;
        }
        $order->gps_session_id = null;
        $order->save();
        DB::afterCommit(function () use ($order, $session): void {
            $this->cache()->forget('gps:'.$session);
            $this->notify($order->id);
        });
    }

    public function start(Order $order, User $user): string
    {
        return DB::transaction(function () use ($order, $user): string {
            $actor = User::lockForUpdate()->findOrFail($user->id);
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $this->authorizeRider($actor, $locked);
            abort_unless($this->eligible($locked), 409, 'Il tracking è disponibile solo durante un ritiro o una consegna attiva.');
            foreach (Order::where('rider_id', $actor->id)->whereNotNull('gps_session_id')->orderBy('id')->lockForUpdate()->get() as $previous) {
                $this->stop($previous);
            }
            $session = (string) Str::uuid();
            $locked->gps_session_id = $session;
            $locked->save();
            DB::afterCommit(fn () => $this->notify($locked->id));

            return $session;
        }, 3);
    }

    /** @param array{session: string, latitude: mixed, longitude: mixed, accuracy: mixed, recorded_at: string} $data */
    public function update(Order $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data): void {
            $actor = User::lockForUpdate()->findOrFail($user->id);
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $this->authorizeRider($actor, $locked);
            abort_unless($this->eligible($locked) && $locked->gps_session_id && hash_equals($locked->gps_session_id, $data['session']), 409, 'Sessione GPS terminata.');
            $previous = $this->position($locked->gps_session_id);
            $recorded = Carbon::parse($data['recorded_at']);
            abort_if($previous && $recorded->lessThanOrEqualTo($previous['recorded_at']), 409, 'Posizione già ricevuta o superata.');
            abort_if($previous && now()->timestamp - $previous['received_at_unix'] < 5, 429, 'Attendi il prossimo aggiornamento.');
            $this->cache()->put('gps:'.$locked->gps_session_id, Crypt::encryptString(json_encode([
                'latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracy' => (float) $data['accuracy'],
                'recorded_at' => $recorded->toIso8601String(), 'received_at' => now()->toIso8601String(), 'received_at_unix' => now()->timestamp,
            ], JSON_THROW_ON_ERROR)), 900);
            $this->sample($locked, $actor, $data, $recorded);
            DB::afterCommit(fn () => $this->notify($locked->id));
        }, 3);
    }

    /** @param array{session: string, latitude: mixed, longitude: mixed, accuracy: mixed, recorded_at: string} $data */
    private function sample(Order $order, User $rider, array $data, Carbon $recorded): void
    {
        $previous = RiderGpsSample::where('rider_id', $rider->id)->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($previous) {
            $seconds = $previous->captured_at->diffInSeconds(now());
            if ($seconds < config('tracking.history_min_seconds')) {
                return;
            }
            $position = $previous->position;
            $latitudeDelta = deg2rad((float) $data['latitude'] - $position['latitude']);
            $longitudeDelta = deg2rad((float) $data['longitude'] - $position['longitude']);
            $arc = sin($latitudeDelta / 2) ** 2 + cos(deg2rad($position['latitude'])) * cos(deg2rad((float) $data['latitude'])) * sin($longitudeDelta / 2) ** 2;
            $distance = 6371000 * 2 * asin(sqrt(min(1, $arc)));
            if ($seconds < config('tracking.history_heartbeat_seconds') && $distance < config('tracking.history_distance_metres')) {
                return;
            }
        }
        RiderGpsSample::create(['rider_id' => $rider->id, 'order_id' => $order->id, 'position' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracy' => (float) $data['accuracy']], 'recorded_at' => $recorded, 'captured_at' => now()]);
    }

    /** @return array<string, mixed>|null */
    private function position(string $session, ?array $cachedPositions = null): ?array
    {
        $value = $cachedPositions === null ? $this->cache()->get('gps:'.$session) : ($cachedPositions['gps:'.$session] ?? null);

        return $value ? json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function expireInactive(): void
    {
        Order::whereNotNull('gps_session_id')->select(['id'])->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                DB::transaction(function () use ($order): void {
                    $locked = Order::lockForUpdate()->find($order->id);
                    if ($locked?->gps_session_id && ! $this->position($locked->gps_session_id) && $locked->updated_at->lt(now()->subMinutes(15))) {
                        $this->stop($locked);
                    }
                });
            }
        });
    }

    public function stopForRider(User $rider): void
    {
        DB::transaction(function () use ($rider): void {
            User::lockForUpdate()->findOrFail($rider->id);
            foreach (Order::where('rider_id', $rider->id)->whereNotNull('gps_session_id')->orderBy('id')->lockForUpdate()->get() as $order) {
                $this->stop($order);
            }
        }, 3);
    }

    public function notify(int $orderId): void
    {
        try {
            RiderLocationUpdated::dispatch($orderId);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
