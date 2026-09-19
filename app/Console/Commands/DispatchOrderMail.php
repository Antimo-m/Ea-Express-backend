<?php

namespace App\Console\Commands;

use App\Jobs\SendOrderMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchOrderMail extends Command
{
    protected $signature = 'orders:dispatch-mail';

    protected $description = 'Accoda i riepiloghi ordine e segnala gli invii con esito incerto';

    public function handle(): int
    {
        if (! config('mail.order_lifecycle_enabled')) {
            $this->warn('Email ordine disattivate. Configurare il trasporto e ORDER_MAIL_ENABLED.');

            return self::SUCCESS;
        }
        DB::table('order_mail_deliveries')->where('state', 'sending')->where('attempted_at', '<', now()->subMinutes(5))->update(['state' => 'uncertain', 'error' => 'WorkerInterrupted', 'updated_at' => now()]);
        DB::table('order_mail_deliveries')->where('state', 'pending')->orderBy('id')->limit(100)->get(['id'])->each(fn (object $row) => SendOrderMail::dispatch($row->id));
        $uncertain = DB::table('order_mail_deliveries')->where('state', 'uncertain')->count();
        if ($uncertain) {
            Log::warning('Email ordine da verificare presso il provider.', ['count' => $uncertain]);
            $this->warn($uncertain.' invii con esito incerto: nessun reinvio automatico.');
        }

        return self::SUCCESS;
    }
}
