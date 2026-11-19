<?php

namespace App\Console\Commands;

use App\Support\PostalCodeIndex;
use Illuminate\Console\Command;

class CachePostalCodes extends Command
{
    protected $signature = 'shipping:cache-postal-codes';

    protected $description = 'Compila l’indice dei Comuni e dei CAP in un file PHP servito da OPcache';

    public function handle(PostalCodeIndex $index): int
    {
        $names = $index->compile();
        $this->components->info('Indice CAP compilato: '.$names.' nomi di Comuni in '.$index->cachePath().'.');

        return self::SUCCESS;
    }
}
