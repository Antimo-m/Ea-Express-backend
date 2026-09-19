<?php

namespace Database\Factories;

use App\Models\PendingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingSettlementFactory extends Factory
{
    public function definition(): array
    {
        return ['pending_account_id' => PendingAccount::factory(), 'user_id' => User::factory(), 'amount_cents' => 500, 'method' => 'cash', 'note' => 'Pagamento test', 'submission_key' => (string) Str::uuid()];
    }
}
