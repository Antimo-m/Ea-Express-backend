<?php

namespace App\Actions;

use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\User;
use App\Support\RiderOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportOrderDelay
{
    public function handle(Order $order, User $user, int $version, ?string $correction = null): bool
    {
        return DB::transaction(function () use ($order, $user, $version, $correction): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($user)->authorize($correction === null ? 'reportDelay' : 'correctDelay', $locked);

            if ($correction === null && $locked->delay_reported_at !== null) {
                return false;
            }
            if ($correction !== null && $locked->delay_reported_at === null) {
                throw ValidationException::withMessages(['delay' => 'La segnalazione è già stata corretta. Ricarica la pagina.']);
            }
            if ($locked->version !== $version) {
                throw ValidationException::withMessages(['version' => 'L’ordine è cambiato. Ricarica la pagina.']);
            }

            $reported = $correction === null;
            $locked->forceFill([
                'delay_reported_at' => $reported ? now() : null,
                'delay_reported_by' => $reported ? $user->id : null,
                'version' => $locked->version + 1,
            ])->save();
            $locked->events()->create([
                'user_id' => $user->id,
                'rider_id' => $locked->rider_id,
                'operational_zone' => app(RiderOperations::class)->zone($locked),
                'status' => $locked->status,
                'delay_action' => $reported ? 'reported' : 'corrected',
                'note' => $correction,
                'public_note' => $reported ? 'Il Rider ha segnalato un ritardo nella spedizione.' : 'La segnalazione di ritardo è stata corretta dall’amministratore.',
            ]);
            WorkspaceUpdated::dispatch($locked->id, 'order');

            return true;
        }, attempts: 3);
    }
}
