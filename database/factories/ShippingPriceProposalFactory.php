<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingPriceProposalFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'proposed_by' => User::factory(), 'previous_price_cents' => 500, 'price_cents' => 600, 'reason' => 'Richiesta particolare', 'state' => 'pending'];
    }
}
