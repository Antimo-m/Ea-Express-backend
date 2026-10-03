<?php

namespace App\Console\Commands;

use App\Models\RiderGpsSample;
use Illuminate\Console\Command;

class PruneRiderGpsSamples extends Command
{
    protected $signature = 'tracking:prune-history';

    protected $description = 'Elimina in lotti i campioni GPS più vecchi del periodo di conservazione';

    public function handle(): int
    {
        RiderGpsSample::where('captured_at', '<', now()->subDays(config('tracking.history_days')))->select('id')->chunkById(1000, function ($samples): void {
            RiderGpsSample::whereIn('id', $samples->modelKeys())->delete();
        });

        return self::SUCCESS;
    }
}
