<?php

namespace Database\Factories;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => (string) Str::uuid(), 'subject' => fake()->company(), 'direction' => 'incoming', 'description' => 'Saldo servizio', 'amount_cents' => 1000, 'created_by' => User::factory()->state(['role' => UserRole::Admin]), 'occurred_on' => now()->toDateString()];
    }
}
