<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RecipientRiskProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => (string) Str::uuid(), 'identity_key' => hash('sha256', fake()->unique()->uuid())];
    }
}
