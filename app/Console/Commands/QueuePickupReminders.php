<?php

namespace App\Console\Commands;

use App\Jobs\SendPickupReminder;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Promemoria email dei ritiri (strategia concordata):
 * - sera (18:00): tutti i ritiri di domani ancora da effettuare;
 * - mattina (7:30): i ritiri di oggi che non hanno ricevuto il promemoria della sera, per esempio quelli
 *   prenotati dopo le 18:00. Chi prenota in giornata dopo l'invio del mattino ha appena confermato e non lo riceve.
 *
 * Un'email per indirizzo con tutti i suoi ritiri della giornata. Ogni ordine viene ricordato al massimo una volta
 * per giornata e tipo; un ritiro riprogrammato riceve il promemoria della nuova data.
 */
class QueuePickupReminders extends Command
{
    protected $signature = 'orders:queue-pickup-reminders {kind : evening (ritiri di domani) oppure morning (ritiri di oggi)}';

    protected $description = 'Prepara e accoda i promemoria email dei ritiri, uno per cliente e giornata, senza duplicati';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, ['evening', 'morning'], true)) {
            $this->error('Tipo non valido: usa evening oppure morning.');

            return self::INVALID;
        }
        if (! config('mail.order_lifecycle_enabled') || ! config('mail.pickup_reminders_enabled')) {
            $this->warn('Promemoria dei ritiri disattivati (ORDER_MAIL_ENABLED / PICKUP_REMINDER_MAIL_ENABLED).');

            return self::SUCCESS;
        }
        $date = now('Europe/Rome')->startOfDay()->addDays($kind === 'evening' ? 1 : 0);
        $orders = $this->dueOrders($date, $kind);
        $queued = 0;
        foreach ($orders->groupBy(fn (Order $order): string => mb_strtolower(trim($order->contact_email))) as $recipient => $group) {
            $deliveryId = DB::transaction(fn (): ?int => $this->record($recipient, $kind, $date, $group));
            if ($deliveryId) {
                SendPickupReminder::dispatch($deliveryId);
                $queued++;
            }
        }
        $this->info($queued.' promemoria accodati per '.$orders->count().' ritiri del '.$date->format('d/m/Y').'.');

        return self::SUCCESS;
    }

    /** @return Collection<int, Order> */
    private function dueOrders(Carbon $date, string $kind): Collection
    {
        $day = $date->toDateString();

        return Order::awaitingPickup()->whereNotNull('contact_email')->where('contact_email', '!=', '')
            ->where('pickup_date', '>=', $day)->where('pickup_date', '<', $date->copy()->addDay()->toDateString())
            ->whereNotExists(fn ($reminded) => $reminded->selectRaw('1')->from('pickup_reminder_orders')->whereColumn('pickup_reminder_orders.order_id', 'orders.id')
                ->where('pickup_reminder_orders.pickup_date', $day)->whereIn('pickup_reminder_orders.kind', $kind === 'morning' ? ['evening', 'morning'] : ['evening']))
            ->withDisplayIdentity()->orderBy('pickup_from')->orderBy('id')->get();
    }

    /** @param Collection<int, Order> $orders */
    private function record(string $recipient, string $kind, Carbon $date, Collection $orders): ?int
    {
        $now = now();
        $deliveryId = DB::table('pickup_reminder_deliveries')->insertGetId([
            'recipient' => $recipient, 'kind' => $kind, 'pickup_date' => $date->toDateString(), 'snapshot' => '[]', 'state' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $claimed = $orders->filter(fn (Order $order): bool => DB::table('pickup_reminder_orders')->insertOrIgnore([
            'delivery_id' => $deliveryId, 'order_id' => $order->id, 'kind' => $kind, 'pickup_date' => $date->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]) === 1);
        if ($claimed->isEmpty()) {
            DB::table('pickup_reminder_deliveries')->where('id', $deliveryId)->delete();

            return null;
        }
        DB::table('pickup_reminder_deliveries')->where('id', $deliveryId)->update(['snapshot' => json_encode([
            'date' => $date->copy()->locale('it')->translatedFormat('l j F Y'),
            'orders' => $claimed->map(fn (Order $order): array => [
                'id' => $order->id, 'reference' => $order->reference,
                'time' => substr((string) $order->pickup_from, 0, 5).'–'.substr((string) $order->pickup_to, 0, 5),
                'pickup' => trim(trim($order->pickup_address.' '.$order->pickup_street_number).', '.$order->pickup_city, ', '),
                'recipient' => $order->recipient_name, 'destination' => $order->delivery_city, 'parcels' => (int) $order->parcel_count,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR)]);

        return $deliveryId;
    }
}
