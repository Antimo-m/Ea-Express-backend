<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => 'EA-'.Str::ulid(), 'tracking_token' => Str::random(64), 'created_by' => User::factory(), 'store_name' => fake()->company(), 'recipient_name' => fake()->name(), 'recipient_phone' => '+390811234567', 'pickup_address' => 'Via Roma 10', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Torino 20', 'delivery_city' => 'Caserta', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'clothing', 'urgency' => 'standard', 'status' => OrderStatus::Received];
    }
}
