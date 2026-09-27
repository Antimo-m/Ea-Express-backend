<?php

namespace App\Console\Commands;

use App\Models\ShippingRate;
use App\Support\PostalCodeResolver;
use App\Support\ShippingQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillShippingRatePostalCodes extends Command
{
    protected $signature = 'shipping:backfill-postal-codes {--apply : Salva i dati territoriali verificati}';

    protected $description = 'Completa i CAP dei listini senza cambiare prezzi, stato o cronologia economica';

    public function handle(PostalCodeResolver $resolver): int
    {
        $counts = ['analizzati' => 0, 'CAP già presenti' => 0, 'completati' => 0, 'con più CAP' => 0, 'già completi' => 0, 'generali fuori regione' => 0, 'da verificare' => 0];
        $unresolved = [];
        DB::transaction(function () use ($resolver, &$counts, &$unresolved): void {
            foreach (ShippingRate::query()->orderBy('id')->lockForUpdate()->get() as $rate) {
                $counts['analizzati']++;
                if ($rate->postal_code || $rate->postal_codes) {
                    $counts['CAP già presenti']++;
                }
                if ($rate->is_default) {
                    $counts['generali fuori regione']++;

                    continue;
                }
                $places = array_values(array_filter($resolver->places($rate->city), fn (array $place): bool => isset($place['verified_source']) && (! $rate->area || str_contains(ShippingQuote::cityKey($rate->area), ShippingQuote::cityKey($place['province'])))));
                if (count($places) !== 1 || $places[0]['postal_codes'] === []) {
                    $unresolved[] = [$rate->id, $rate->city, $rate->area, 'Comune non identificato con certezza nella fonte verificata'];
                    $counts['da verificare']++;
                    if ($this->option('apply')) {
                        $rate->timestamps = false;
                        $rate->territory_review_required = true;
                        $rate->saveQuietly();
                    }

                    continue;
                }
                $place = $places[0];
                $codes = $place['postal_codes'];
                if (($rate->postal_code && ! in_array($rate->postal_code, $codes, true)) || array_diff($rate->postal_codes ?? [], $codes)) {
                    $unresolved[] = [$rate->id, $rate->city, $rate->area, 'CAP esistente incompatibile: nessuna sovrascrittura'];
                    $counts['da verificare']++;
                    if ($this->option('apply')) {
                        $rate->timestamps = false;
                        $rate->territory_review_required = true;
                        $rate->saveQuietly();
                    }

                    continue;
                }
                $targetCodes = $rate->postal_code ? [$rate->postal_code] : ($rate->postal_codes ?: $codes);
                $data = ['territory_review_required' => false, 'city' => $place['name'], 'city_key' => ShippingQuote::cityKey($place['name']), 'postal_code' => count($targetCodes) === 1 ? $targetCodes[0] : null, 'postal_codes' => $targetCodes];
                $rate->fill($data);
                if (! $rate->isDirty(array_keys($data))) {
                    $counts['già completi']++;

                    continue;
                }
                $counts['completati']++;
                if (count($targetCodes) > 1) {
                    $counts['con più CAP']++;
                }
                if ($this->option('apply')) {
                    $rate->timestamps = false;
                    $rate->saveQuietly();
                }
            }
        });
        $this->table(['Esito', 'Numero'], array_map(fn (string $label, int $count): array => [$label, $count], array_keys($counts), array_values($counts)));
        if ($unresolved) {
            $this->table(['ID', 'Località', 'Area', 'Motivo'], $unresolved);
        }
        $this->info($this->option('apply') ? 'Backfill salvato. Prezzi, area, tempi, stato, vettore e riferimenti storici invariati.' : 'Anteprima: nessuna scrittura. Usa --apply per salvare.');

        return self::SUCCESS;
    }
}
