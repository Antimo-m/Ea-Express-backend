<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FinancialMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'kind' => 'income', 'description' => fake()->sentence(), 'amount_cents' => 1000, 'occurred_on' => now()->toDateString(), 'submission_key' => (string) Str::uuid()];
    }
}
