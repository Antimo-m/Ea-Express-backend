<?php

namespace Database\Factories;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class SenderAddressFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => User::factory()->state(['role' => UserRole::Customer]), 'pickup_address' => 'Via Roma', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'pickup_city' => 'Napoli'];
    }
}
