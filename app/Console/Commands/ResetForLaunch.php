<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Azzeramento per la consegna: elimina account, ordini, movimenti e storico di prova, conservando listini,
 * controllo contabile e migrazioni. Senza --apply mostra soltanto i conteggi.
 */
class ResetForLaunch extends Command
{
    public const string Confirmation = 'AZZERA';

    /** Tabelle conservate: listini, controllo contabile e stato delle migrazioni. */
    public const array Kept = ['migrations', 'shipping_rates', 'accounting_controls'];

    /** Ordine di cancellazione compatibile con le chiavi esterne (prima i figli, poi i genitori). */
    public const array Cleared = [
        'payment_entries', 'receipt_reversals', 'receipt_declarations', 'expenses', 'pending_settlements', 'pending_accounts',
        'financial_movements', 'shipping_price_proposals', 'recipient_incidents', 'recipient_risk_profiles',
        'order_events', 'order_messages', 'order_mail_deliveries', 'pickup_reminder_orders', 'pickup_reminder_deliveries', 'rider_gps_samples',
        'orders', 'economic_audits', 'notifications', 'sender_addresses', 'trusted_rider_devices', 'rider_activation_tokens',
        'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'users',
    ];

    protected $signature = 'app:reset-for-launch {--apply : Elimina davvero i dati dopo la conferma}';

    protected $description = 'Svuota account e dati di prova per la consegna, conservando listini e controllo contabile';

    public function handle(): int
    {
        $tables = array_map(fn ($table): string => is_array($table) ? $table['name'] : (string) $table, Schema::getTables());
        $unknown = array_diff($tables, self::Kept, self::Cleared);
        if ($unknown !== []) {
            $this->error('Tabelle non previste, nessun dato modificato: '.implode(', ', $unknown));

            return self::FAILURE;
        }
        $cleared = array_values(array_intersect(self::Cleared, $tables));
        $this->table(['Tabella', 'Righe', 'Azione'], [
            ...array_map(fn (string $table): array => [$table, DB::table($table)->count(), 'Elimina'], $cleared),
            ...array_map(fn (string $table): array => [$table, DB::table($table)->count(), 'Conserva'], array_intersect(self::Kept, $tables)),
        ]);
        if (! $this->option('apply')) {
            $this->info('Simulazione: nessun dato modificato. Ripeti con --apply dopo backup verificato e sito in manutenzione.');

            return self::SUCCESS;
        }
        if ($this->ask('Operazione irreversibile. Scrivi '.self::Confirmation.' per eliminare i dati') !== self::Confirmation) {
            $this->warn('Conferma non valida: nessun dato modificato.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($cleared): void {
            // Gli storni puntano all'incasso originale: si scollegano prima di eliminare i movimenti.
            DB::table('payment_entries')->whereNotNull('original_entry_id')->update(['original_entry_id' => null]);
            DB::table('accounting_controls')->update(['updated_by' => null]);
            foreach ($cleared as $table) {
                DB::table($table)->delete();
            }
        });
        $this->info('Dati eliminati. Listini e controllo contabile conservati. Ora crea l’account Admin.');

        return self::SUCCESS;
    }
}
