<?php

namespace App\Console\Commands;

use App\Support\DeactivatedAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Dry-run report of disabled accounts past the retention review period. It never changes data.
 */
class ReviewDeactivatedAccounts extends Command
{
    protected $signature = 'accounts:review-deactivated {--json : Restituisce il report in formato JSON}';

    protected $description = 'Simulazione: elenca gli account disabilitati da oltre il periodo di revisione e i dati da conservare (nessuna modifica)';

    public function handle(DeactivatedAccounts $accounts): int
    {
        $rows = [];
        $accounts->due()->orderBy('deactivated_at')->orderBy('id')->chunkById(200, function ($users) use ($accounts, &$rows): void {
            foreach ($users as $user) {
                $retention = $accounts->retention($user);
                $rows[] = ['account' => $user->slug, 'ruolo' => $user->role->label(), 'disabilitato_il' => $user->deactivated_at->timezone('Europe/Rome')->format('d/m/Y'), 'giorni' => (int) $user->deactivated_at->diffInDays(now()), 'ordini' => $retention['orders'], 'registrazioni_contabili' => $retention['financial'], 'sospesi_aperti' => $retention['pending'], 'proposta' => $retention['proposal']];
            }
        });

        Log::info('accounts.deactivated_review', ['due' => count($rows), 'days' => DeactivatedAccounts::days(), 'dry_run' => true]);
        if ($this->option('json')) {
            $this->line(json_encode(['dry_run' => true, 'days' => DeactivatedAccounts::days(), 'accounts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info('Simulazione (dry-run): nessun account viene eliminato o modificato.');
        $this->line('Account disabilitati da oltre '.DeactivatedAccounts::days().' giorni: '.count($rows));
        if ($rows) {
            $this->table(array_keys($rows[0]), $rows);
        }

        return self::SUCCESS;
    }
}
