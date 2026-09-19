<?php

namespace App\Console\Commands;

use App\Actions\ReviseShippingRate;
use App\Models\ShippingRate;
use App\Models\User;
use App\Support\Money;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportShippingRates extends Command
{
    protected $signature = 'shipping:import-rates {file : CSV UTF-8} {--user= : ID amministratore per audit} {--dry-run : Valida senza scrivere}';

    protected $description = 'Importa versioni tariffarie senza alterare gli ordini storici';

    public function handle(ReviseShippingRate $revise): int
    {
        $file = $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error('CSV non leggibile.');

            return self::FAILURE;
        }
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        $expected = ['area', 'city', 'postal_code', 'price', 'delivery_time', 'source_reference'];
        if ($header !== $expected) {
            fclose($handle);
            $this->error('Intestazioni richieste: '.implode(',', $expected));

            return self::FAILURE;
        }
        $rows = [];
        $seen = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (count($values) !== count($header)) {
                fclose($handle);
                $this->error("Riga $line incompleta.");

                return self::FAILURE;
            }
            $data = array_map(fn ($v) => trim($v) === '' ? null : trim($v), array_combine($header, $values));
            $validator = Validator::make($data, ['area' => 'nullable|string|max:100', 'city' => 'required|string|max:100', 'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/D'], 'price' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'delivery_time' => 'nullable|string|max:100', 'source_reference' => 'required|string|max:255']);
            $key = ShippingQuote::cityKey($data['city'] ?? '').'|'.($data['postal_code'] ?? '').'|'.($data['area'] ?? '');
            if ($validator->fails() || isset($seen[$key])) {
                fclose($handle);
                $this->error("Riga $line non valida o duplicata: ".$validator->errors()->first());

                return self::FAILURE;
            }
            $seen[$key] = true;
            $data['price_cents'] = Money::cents($data['price']);
            unset($data['price']);
            $data['active'] = true;
            $rows[] = $data;
        }fclose($handle);
        if ($this->option('dry-run')) {
            $this->info(count($rows).' tariffe valide. Nessuna scrittura.');

            return self::SUCCESS;
        }
        $user = User::where('role', UserRole::Admin)->where('is_active', true)->find($this->option('user'));
        if (! $user) {
            $this->error('Indica --user con un amministratore attivo.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($rows, $user, $revise): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ($rows as $data) {
                $existing = ShippingRate::where('active', true)->where('city_key', ShippingQuote::cityKey($data['city']))->where('postal_code', $data['postal_code'])->where('area', $data['area'])->lockForUpdate()->get();
                if ($existing->count() > 1) {
                    throw new \RuntimeException('Tariffe attive ambigue per '.$data['city']);
                }
                $rate = $existing->first();
                if ($rate && $rate->price_cents === $data['price_cents'] && $rate->delivery_time === $data['delivery_time'] && $rate->source_reference === $data['source_reference']) {
                    continue;
                }
                $revise->handle($user, $data, $rate);
            }
        }, 3);
        $this->info(count($rows).' tariffe elaborate.');

        return self::SUCCESS;
    }
}
