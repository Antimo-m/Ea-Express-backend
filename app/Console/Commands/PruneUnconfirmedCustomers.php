<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\SecurityEvent;
use App\Support\UnconfirmedCustomers;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneUnconfirmedCustomers extends Command
{
    protected $signature = 'customers:prune-unconfirmed {--hours=72 : Età minima degli account mai confermati}';

    protected $description = 'Elimina gli account cliente mai confermati con l’OTP del primo accesso e privi di dati collegati';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 24) {
            $this->error('Indica almeno 24 ore.');

            return self::INVALID;
        }
        $deleted = 0;
        $skipped = 0;
        UnconfirmedCustomers::query()->where('created_at', '<', now()->subHours($hours))->select('id')->chunkById(200, function ($users) use (&$deleted, &$skipped): void {
            foreach ($users as $candidate) {
                try {
                    DB::transaction(function () use ($candidate, &$deleted): void {
                        $user = UnconfirmedCustomers::query()->whereKey($candidate->id)->lockForUpdate()->first();
                        if ($user instanceof User) {
                            $user->delete();
                            $deleted++;
                        }
                    }, 3);
                } catch (QueryException) {
                    $skipped++;
                    Log::warning('Account cliente non confermato non eliminato: dati collegati.', ['user_id' => $candidate->id]);
                }
            }
        });
        if ($deleted) {
            SecurityEvent::record('unconfirmed_customers_pruned');
        }
        $this->info("Account eliminati: {$deleted}. Saltati: {$skipped}.");

        return self::SUCCESS;
    }
}
