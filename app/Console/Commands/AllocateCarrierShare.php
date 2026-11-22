<?php

namespace App\Console\Commands;

use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Support\AccountingPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AllocateCarrierShare extends Command
{
    protected $signature = 'payments:allocate-carrier-share {--apply : Salva le quote corrette}';

    protected $description = 'Esclude il costo vettore dagli incassi fuori regione registrati con la tariffa intera come quota EA-Express';

    public function handle(RecordEconomicAudit $audit): int
    {
        $rows = [];
        DB::transaction(function () use ($audit, &$rows): void {
            $period = AccountingPeriod::lock();
            $orders = Order::where('shipping_type', 'external')->where('carrier_cost_cents', '>', 0)
                ->whereHas('payments', fn ($payments) => $payments->where('amount_cents', '>', 0)->whereColumn('ea_amount_cents', 'amount_cents'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($orders as $order) {
                $payments = $order->payments()->orderBy('id')->lockForUpdate()->get();
                if ($payments->contains(fn (PaymentEntry $entry): bool => $entry->amount_cents < 0 || $entry->receipt_reversal_id !== null)) {
                    $rows[] = [$order->reference, '—', '—', 'Storni presenti: correggi manualmente'];

                    continue;
                }
                $carrierOpen = (int) $order->carrier_cost_cents;
                foreach ($payments as $entry) {
                    $isFullShare = $entry->amount_cents > 0 && $entry->ea_amount_cents === $entry->amount_cents;
                    $share = $isFullShare ? max(0, $entry->amount_cents - $carrierOpen) : (int) ($entry->ea_amount_cents ?? $entry->amount_cents);
                    $carrierOpen = max(0, $carrierOpen - ($entry->amount_cents - $share));
                    if (! $isFullShare || $share === $entry->ea_amount_cents) {
                        continue;
                    }
                    $closed = false;
                    try {
                        $period->assertOpen($entry->created_at);
                    } catch (HttpException) {
                        $closed = true;
                    }
                    $rows[] = [$order->reference, number_format($entry->ea_amount_cents / 100, 2, ',', '.'), number_format($share / 100, 2, ',', '.'), $closed ? 'Periodo chiuso: non modificato' : ($this->option('apply') ? 'Corretto' : 'Da correggere')];
                    if ($closed || ! $this->option('apply')) {
                        continue;
                    }
                    $before = $entry->toArray();
                    $entry->ea_amount_cents = $share;
                    $entry->save();
                    $audit->handle(null, $entry, 'payment.corrected', $before, [...$entry->toArray(), 'reason' => 'Quota EA-Express ricalcolata: costo vettore escluso dal bilancio']);
                }
            }
        });
        $this->table(['Ordine', 'Quota EA prima', 'Quota EA corretta', 'Esito'], $rows);
        if (! $this->option('apply') && $rows !== []) {
            $this->info('Nessun dato modificato. Ripeti con --apply per salvare.');
        }

        return self::SUCCESS;
    }
}
