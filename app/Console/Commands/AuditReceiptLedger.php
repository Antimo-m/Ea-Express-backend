<?php

namespace App\Console\Commands;

use App\Models\EconomicAudit;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AuditReceiptLedger extends Command
{
    protected $signature = 'receipts:audit {--order= : UUID pubblico della spedizione}';

    protected $description = 'Controllo di sola lettura degli incassi; elenca anomalie e verifiche necessarie senza modificare dati';

    public function handle(): int
    {
        $slug = $this->option('order');
        if ($slug && ! Str::isUuid($slug)) {
            $this->error('Usa lo slug UUID pubblico della spedizione.');

            return self::INVALID;
        }
        $issues = 0;
        Order::query()->when($slug, fn ($q) => $q->where('slug', $slug))->where(fn ($q) => $q->whereHas('payments')->orWhereNotNull('receipt_voided_at')->orWhereNotNull('paid_at'))
            ->with(['payments' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])->chunkById(200, function ($orders) use (&$issues): void {
                foreach ($orders as $order) {
                    $entries = $order->payments;
                    $net = (int) $entries->sum('amount_cents');
                    $cash = (int) $entries->sum(fn ($entry) => $entry->cash_amount_cents ?? $entry->amount_cents);
                    $codes = [];
                    if ($entries->contains(fn ($entry) => $entry->kind === 'restoration' && $entry->amount_cents > 0)) {
                        $codes[] = 'legacy_restoration: ripristino storico con ricavo positivo; verificare incassi successivi e registrare soltanto eventuali compensazioni approvate nel periodo aperto';
                    }
                    if ($entries->contains(fn ($entry) => $entry->amount_cents < 0 && ! $entry->original_entry_id)) {
                        $codes[] = 'missing_original: identificare gli incassi originali e verificare se il rimborso sia stato pagato; preservare date e importi';
                        $available = collect();
                        foreach ($entries as $entry) {
                            if ($entry->amount_cents > 0) {
                                $available->push($entry);
                            } elseif ($entry->amount_cents < 0) {
                                $candidates = $available->where('amount_cents', -$entry->amount_cents);
                                if ($candidates->count() === 1) {
                                    $candidate = $candidates->first();
                                    $this->line($order->slug.' | proposta da verificare: storno '.$entry->slug.' → incasso '.$candidate->slug.'; nessuna modifica a cassa, importi o date');
                                    $available = $available->reject(fn ($original) => $original->id === $candidate->id);
                                } else {
                                    $this->line($order->slug.' | storno '.$entry->slug.': collegamento ambiguo, verificare le ricevute prima di correggere');
                                }
                            }
                        }
                    }
                    if ($net < 0 || $net > (int) $order->price_cents) {
                        $codes[] = 'invalid_net: verificare rettifiche duplicate o incassi oltre tariffa';
                    }
                    if ($order->receipt_voided_at && $net !== 0) {
                        $codes[] = 'voided_with_balance: verificare la quota effettivamente da rettificare';
                    }
                    if ($order->paid_at && $cash !== (int) $order->price_cents) {
                        $codes[] = 'paid_mismatch: riconciliare ricevute e saldo prima di confermare il pagamento';
                    }
                    if (! $order->receipt_voided_at && ! $order->paid_at && $entries->contains(fn ($entry) => $entry->amount_cents < 0 && ! $entry->original_entry_id) && EconomicAudit::where('entity_type', 'orders')->where('entity_id', $order->id)->where('action', 'receipt.restore')->exists()) {
                        $codes[] = 'restored_uncollected: verificare il vecchio ripristino prima di chiedere un nuovo pagamento';
                    }
                    foreach ($codes as $code) {
                        $this->line($order->slug.' | '.$code);
                        $issues++;
                    }
                }
            });
        $this->info($issues.' anomalie da verificare. Nessun dato modificato.');
        $this->line('Correzione: confrontare ricevuta, storno, audit e prova del rimborso; collegare soltanto movimenti verificati. Eventuali compensazioni vanno registrate nel periodo aperto, senza cancellare o retrodatare lo storico.');

        return $issues ? self::FAILURE : self::SUCCESS;
    }
}
