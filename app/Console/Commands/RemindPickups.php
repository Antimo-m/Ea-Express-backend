<?php

namespace App\Console\Commands;

use App\Actions\NotifyOrderParticipants;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindPickups extends Command
{
    protected $signature = 'orders:remind-pickups';

    protected $description = 'Registra un promemoria giornaliero per i ritiri di oggi e arretrati, senza duplicarlo';

    public function handle(NotifyOrderParticipants $notify): int
    {
        $today = now('Europe/Rome')->toDateString();
        $count = 0;
        Order::awaitingPickup()->where('pickup_date', '<', now('Europe/Rome')->addDay()->toDateString())
            ->where(fn ($query) => $query->whereNull('pickup_reminded_on')->orWhere('pickup_reminded_on', '<', $today))
            ->select('id')->chunkById(100, function ($orders) use ($notify, $today, &$count): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, $notify, $today, &$count): void {
                        $locked = Order::lockForUpdate()->find($order->id);
                        if (! $locked || $locked->pickup_date->toDateString() > $today || $locked->pickup_reminded_on?->toDateString() === $today || ! Order::awaitingPickup()->whereKey($locked->id)->exists()) {
                            return;
                        }
                        $notify->handle($locked, $locked->pickup_date->toDateString() < $today ? 'Ritiro arretrato: verifica o ripianifica la richiesta' : 'Ritiro previsto oggi · '.substr($locked->pickup_from, 0, 5).'–'.substr($locked->pickup_to, 0, 5), null);
                        $locked->pickup_reminded_on = $today;
                        $locked->save();
                        $count++;
                    }, 3);
                }
            });
        $this->info($count.' promemoria registrati.');

        return self::SUCCESS;
    }
}
