<?php

namespace App\Console\Commands;

use App\Models\ShippingRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeExternalRate extends Command
{
    protected $signature = 'shipping:initialize-external-rate';

    protected $description = 'Configura una sola volta la tariffa generale Fuori regione a 7 euro';

    public function handle(): int
    {
        if (ShippingRate::where('is_default', true)->exists()) {
            $this->info('La tariffa generale esiste già. Prezzo e stato conservati.');

            return self::SUCCESS;
        }
        DB::transaction(function (): void {
            $rate = ShippingRate::create([
                'is_default' => true, 'shipping_type' => 'external', 'city' => 'Fuori regione',
                'city_key' => 'fuori regione', 'price_cents' => 700, 'carrier_cost_cents' => 0,
                'active' => true, 'source_reference' => 'Tariffa iniziale configurabile',
            ]);
            DB::table('economic_audits')->insert([
                'entity_type' => 'shipping_rates', 'entity_id' => $rate->id, 'action' => 'rate.initialized',
                'after' => json_encode($rate->toArray(), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->info('Tariffa Fuori regione creata a 7,00 euro. Modificabile nei Listini.');

        return self::SUCCESS;
    }
}
