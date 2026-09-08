<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'description' => 'Carburante', 'amount_cents' => 1500, 'spent_on' => now()->toDateString()];
    }
}
