<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => (string) Str::uuid(), 'user_id' => User::factory(), 'description' => 'Carburante', 'amount_cents' => 1500, 'spent_on' => now()->toDateString()];
    }
}
