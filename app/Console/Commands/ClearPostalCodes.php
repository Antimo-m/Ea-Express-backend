<?php

namespace App\Console\Commands;

use App\Support\PostalCodeIndex;
use Illuminate\Console\Command;

class ClearPostalCodes extends Command
{
    protected $signature = 'shipping:clear-postal-codes';

    protected $description = 'Rimuove l’indice compilato dei Comuni e dei CAP';

    public function handle(PostalCodeIndex $index): int
    {
        $index->clear();
        $this->components->info('Indice CAP compilato rimosso.');

        return self::SUCCESS;
    }
}
