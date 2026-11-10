<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\RecipientRiskProfile;
use App\Models\User;
use App\Support\RecipientIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecipientIncidentFactory extends Factory
{
    public function definition(): array
    {
        $recipient = ['recipient_name' => fake()->name(), 'recipient_phone' => '+393331234567', 'delivery_address' => 'Via Roma', 'delivery_street_number' => '50', 'delivery_postal_code' => '80100', 'delivery_city' => 'Napoli'];

        return ['recipient_risk_profile_id' => RecipientRiskProfile::factory(), 'order_id' => Order::factory(), 'recorded_by' => User::factory(), ...RecipientIdentity::keys($recipient), 'recipient' => $recipient, 'reason' => 'recipient_absent', 'occurred_at' => now()];
    }
}
