<?php

namespace App\Jobs;

use App\Mail\OrderLifecycle;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderMail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = DB::table('order_mail_deliveries')->find($this->deliveryId);
        if (! $delivery || $delivery->state !== 'pending') {
            return;
        }
        $mail = new OrderLifecycle(json_decode($delivery->snapshot, true, flags: JSON_THROW_ON_ERROR), $delivery->event);
        $mail->render();
        $claimed = DB::table('order_mail_deliveries')->where('id', $this->deliveryId)->where('state', 'pending')->update(['state' => 'sending', 'attempted_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            Mail::to($delivery->recipient)->send($mail);
            DB::table('order_mail_deliveries')->where('id', $this->deliveryId)->update(['state' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $exception) {
            DB::table('order_mail_deliveries')->where('id', $this->deliveryId)->update(['state' => 'uncertain', 'error' => class_basename($exception), 'updated_at' => now()]);
            Log::error('Esito email da verificare prima di un eventuale reinvio.', ['delivery_id' => $this->deliveryId, 'error_type' => class_basename($exception)]);
            $this->fail($exception);
        }
    }
}
