<?php

namespace App\Jobs;

use App\Mail\PickupReminder;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Invia un promemoria dei ritiri. Prima dell'invio ricontrolla gli ordini: quelli annullati, già ritirati o
 * spostati ad altra data nel frattempo vengono tolti; se non ne resta nessuno l'email non parte.
 * Come per le email ordine, un invio con esito incerto non viene ripetuto automaticamente.
 */
class SendPickupReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = DB::table('pickup_reminder_deliveries')->find($this->deliveryId);
        if (! $delivery || $delivery->state !== 'pending') {
            return;
        }
        $snapshot = json_decode($delivery->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $date = substr((string) $delivery->pickup_date, 0, 10);
        $stillDue = Order::awaitingPickup()->whereKey(array_column($snapshot['orders'], 'id'))
            ->where('pickup_date', '>=', $date)->where('pickup_date', '<', Carbon::parse($date)->addDay()->toDateString())->pluck('id')->all();
        $snapshot['orders'] = array_values(array_filter($snapshot['orders'], fn (array $order): bool => in_array($order['id'], $stillDue, true)));
        if ($snapshot['orders'] === []) {
            DB::table('pickup_reminder_deliveries')->where('id', $this->deliveryId)->where('state', 'pending')->update(['state' => 'skipped', 'updated_at' => now()]);

            return;
        }
        $mail = new PickupReminder($snapshot, $delivery->kind);
        $mail->render();
        $claimed = DB::table('pickup_reminder_deliveries')->where('id', $this->deliveryId)->where('state', 'pending')->update(['state' => 'sending', 'attempted_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            Mail::to($delivery->recipient)->send($mail);
            DB::table('pickup_reminder_deliveries')->where('id', $this->deliveryId)->update(['state' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $exception) {
            DB::table('pickup_reminder_deliveries')->where('id', $this->deliveryId)->update(['state' => 'uncertain', 'error' => class_basename($exception), 'updated_at' => now()]);
            Log::error('Esito del promemoria ritiri da verificare prima di un eventuale reinvio.', ['delivery_id' => $this->deliveryId, 'error_type' => class_basename($exception)]);
            $this->fail($exception);
        }
    }
}
