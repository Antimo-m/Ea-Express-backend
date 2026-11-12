<?php

namespace Tests\Feature;

use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeparateBalanceTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_settlements_move_each_balance_once_and_manual_cash_stays_separate(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $incoming = PendingAccount::factory()->create(['direction' => 'incoming', 'amount_cents' => 10000]);
        $outgoing = PendingAccount::factory()->create(['direction' => 'outgoing', 'amount_cents' => 4000]);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 2500]);
        $this->actingAs($admin)->get('/balance')->assertOk()
            ->assertViewHas('finalNet', 0)->assertViewHas('pendingBalance', 6000)->assertViewHas('cashBalance', 2500)
            ->assertSee('Saldo finale')->assertSee('Saldo sospeso');
        $receipt = ['version' => 1, 'amount' => '30', 'submission_key' => (string) Str::uuid()];
        $payment = ['version' => 1, 'amount' => '10', 'submission_key' => (string) Str::uuid()];

        $this->postJson(route('pending.settle', $incoming), $receipt)->assertOk();
        $this->postJson(route('pending.settle', $incoming), $receipt)->assertOk();
        $this->postJson(route('pending.settle', $outgoing), $payment)->assertOk();
        $this->postJson(route('pending.settle', $outgoing), $payment)->assertOk();

        $this->get('/balance')->assertViewHas('grossIncome', 3000)->assertViewHas('totalExpenses', 1000)
            ->assertViewHas('operatingNet', 2000)->assertViewHas('finalNet', 2000)
            ->assertViewHas('pendingIncoming', 7000)->assertViewHas('pendingOutgoing', 3000)
            ->assertViewHas('pendingBalance', 4000)->assertViewHas('cashBalance', 4500);
        $this->assertDatabaseCount('pending_settlements', 2);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(3000, $incoming->fresh()->settled_cents);
        $this->assertSame(1000, $outgoing->fresh()->settled_cents);
    }

    public function test_reversal_refund_and_restore_keep_suspended_balance_independent_without_duplicate_receipts(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PendingAccount::factory()->create(['direction' => 'outgoing', 'amount_cents' => 500]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);

        foreach (['receive', 'reverse', 'refund', 'restore'] as $index => $action) {
            $this->postJson(route('payments.store', $order), ['action' => $action, 'version' => $index + 1, 'note' => 'Operazione contabile documentata'])->assertOk();
            $this->get('/balance')->assertViewHas('finalNet', $action === 'receive' ? 2000 : 0)
                ->assertViewHas('pendingBalance', -500)
                ->assertViewHas('cashBalance', in_array($action, ['receive', 'reverse']) ? 2000 : 0);
        }

        $this->assertDatabaseCount('payment_entries', 4);
        $this->assertSame(0, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(0, (int) $order->payments()->sum('cash_amount_cents'));
    }

    public function test_multiple_customer_slugs_and_period_preserve_historical_suspended_balances(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(3)->create(['role' => UserRole::Customer]);
        $incoming = PendingAccount::factory()->create(['customer_id' => $customers[0]->id, 'direction' => 'incoming', 'amount_cents' => 1000, 'occurred_on' => '2026-10-10']);
        PendingAccount::factory()->create(['customer_id' => $customers[1]->id, 'direction' => 'outgoing', 'amount_cents' => 400, 'occurred_on' => '2026-10-10']);
        PendingAccount::factory()->create(['customer_id' => $customers[2]->id, 'direction' => 'incoming', 'amount_cents' => 99999, 'occurred_on' => '2026-10-10']);
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $this->actingAs($admin)->postJson(route('pending.settle', $incoming), ['version' => 1, 'amount' => '3', 'submission_key' => (string) Str::uuid()])->assertOk();
        $query = http_build_query(['customer_ids' => [$customers[0]->slug, $customers[1]->slug, $customers[0]->slug]]);

        $this->get('/balance?month=2026-10&'.$query)->assertOk()->assertViewHas('finalNet', 0)
            ->assertViewHas('pendingBalance', 600)->assertViewHas('pendingUncertain', 0);
        $this->get('/balance?month=2026-11&'.$query)->assertOk()->assertViewHas('finalNet', 300)
            ->assertViewHas('pendingBalance', 300)->assertViewHas('pendingUncertain', 0);
    }

    public function test_incomplete_history_never_presents_a_partial_suspended_sum_as_a_verified_balance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PendingAccount::factory()->create(['direction' => 'incoming', 'amount_cents' => 1000, 'occurred_on' => '2026-10-10']);
        $uncertain = PendingAccount::factory()->create(['direction' => 'outgoing', 'amount_cents' => 400, 'occurred_on' => '2026-10-10']);
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $uncertain->forceFill(['state' => 'paid', 'settled_cents' => 400])->save();

        $page = $this->actingAs($admin)->get('/balance?month=2026-10')->assertOk();

        $page->assertViewHas('finalNet', 0)->assertViewHas('pendingUncertain', 1)
            ->assertViewHas('pendingBalance', fn ($balance): bool => $balance === null)
            ->assertSee('data-balance="pending"><dt>Saldo sospeso</dt><dd>Da verificare</dd>', false);
        $this->get('/balance?month=2026-11')->assertViewHas('pendingBalance', fn ($balance): bool => $balance === null);
    }
}
