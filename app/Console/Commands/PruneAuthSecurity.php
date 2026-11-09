<?php

namespace App\Console\Commands;

use App\Support\AuthSecurityStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

class PruneAuthSecurity extends Command
{
    protected $signature = 'auth-security:prune {--batch=1000} {--batches=100}';

    protected $description = 'Elimina soltanto contatori e lock di autenticazione scaduti dal cache store database';

    public function handle(AuthSecurityStore $security): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $batches = filter_var($this->option('batches'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 10000 || $batches === false || $batches < 1 || $batches > 1000) {
            $this->error('Usa batch tra 1 e 10000 e batches tra 1 e 1000.');

            return self::INVALID;
        }
        $configuration = config('cache.stores.'.config('auth_security.store'));
        if (($configuration['driver'] ?? null) !== 'database') {
            $this->info('Questo store gestisce autonomamente le scadenze.');

            return self::SUCCESS;
        }
        $store = $security->cache()->getStore();
        if (! $store instanceof DatabaseStore) {
            return self::FAILURE;
        }
        $prefix = $store->getPrefix().'auth:';
        $removed = 0;
        foreach ([[$store->getConnection(), $configuration['table'] ?? 'cache'], [$store->getLockConnection(), $configuration['lock_table'] ?? 'cache_locks']] as [$connection, $table]) {
            for ($iteration = 0; $iteration < $batches; $iteration++) {
                $keys = $this->expired($connection, $table, $prefix)->orderBy('key')->limit($batch)->pluck('key');
                if ($keys->isEmpty()) {
                    break;
                }
                $removed += $this->expired($connection, $table, $prefix)->whereIn('key', $keys)->delete();
            }
        }
        $this->info('Chiavi di autenticazione scadute eliminate: '.$removed);

        return self::SUCCESS;
    }

    private function expired(Connection $connection, string $table, string $prefix): Builder
    {
        return $connection->table($table)->where('expiration', '<=', now()->timestamp)
            ->whereRaw('SUBSTR('.$connection->getQueryGrammar()->wrap('key').', 1, ?) = ?', [strlen($prefix), $prefix]);
    }
}
