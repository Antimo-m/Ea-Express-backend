<?php

namespace Database\Factories;

use App\Support\ShippingQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingRateFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->unique()->city();

        return ['area' => 'Zona test', 'city' => $city, 'city_key' => ShippingQuote::cityKey($city), 'price_cents' => 500, 'active' => true, 'delivery_time' => '24 ore', 'source_reference' => 'Dati sintetici per test'];
    }
}
