<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderMessage> */
class OrderMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'body' => fake()->sentence()];
    }
}
