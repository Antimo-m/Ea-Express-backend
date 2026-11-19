<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\Support\BalanceStatement;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-16 12:00', 'Europe/Rome'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
    }

    /** @return array<string, int|null> */
    private function statement(string $query = ''): array
    {
        return $this->get('/balance'.($query !== '' ? '?'.$query : ''))->assertOk()->viewData('statement');
    }

    private function deliveredOrder(int $cents, ?User $customer = null): Order
    {
        return Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => $cents, 'customer_id' => $customer?->id]);
    }

    private function receive(Order $order): void
    {
        $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => $order->fresh()->version])->assertOk();
    }

    private function pending(string $direction, int $euro, ?User $customer = null): PendingAccount
    {
        $customer ??= User::factory()->create(['role' => UserRole::Customer]);
        $this->postJson('/pending', ['customer_id' => $customer->slug, 'subject' => 'Posizione '.$direction, 'direction' => $direction, 'description' => 'Servizio', 'amount' => (string) $euro, 'occurred_on' => now()->toDateString()])->assertOk();

        return PendingAccount::latest('id')->first();
    }

    private function settle(PendingAccount $account, int $euro): void
    {
        $this->postJson('/pending/'.$account->slug.'/settlements', ['amount' => (string) $euro, 'method' => 'cash', 'note' => 'Saldo', 'submission_key' => (string) Str::uuid(), 'version' => $account->fresh()->version])->assertOk();
    }

    public function test_formulas_are_pure_and_keep_unknown_pending_values_explicit(): void
    {
        $flows = ['receipts' => 5000, 'expenses' => 1200, 'manual_in' => 300, 'manual_out' => 100];
        $statement = BalanceStatement::make($flows, ['receipts' => 1000, 'expenses' => 400, 'manual_in' => 0, 'manual_out' => 0], 2500, 700);
        $this->assertSame(['opening' => 600, 'collected' => 5300, 'expenses' => 1300, 'available' => 4000, 'closing' => 4600, 'pending' => 1800, 'accounting' => 5800], $statement);
        $unknown = BalanceStatement::make($flows, $flows, null, 700);
        $this->assertNull($unknown['pending']);
        $this->assertNull($unknown['accounting']);
    }

    public function test_no_movements_shows_zero_and_no_placeholder_labels(): void
    {
        $this->assertSame(['opening' => 0, 'collected' => 0, 'expenses' => 0, 'available' => 0, 'closing' => 0, 'pending' => 0, 'accounting' => 0], $this->statement());
        $this->get('/balance')->assertSee(\App\Support\Money::format(0))->assertDontSee('Da riconciliare')->assertDontSee('Storni attivi')->assertSee('Storni')
            ->assertViewHas('refundsDue', 0);
    }

    public function test_receipts_and_expenses(): void
    {
        $this->receive($this->deliveredOrder(3000));
        $this->assertSame(3000, $this->statement()['available']);
        Expense::factory()->create(['user_id' => $this->admin->id, 'amount_cents' => 1250, 'spent_on' => now()->toDateString()]);
        $statement = $this->statement();
        $this->assertSame([3000, 1250, 1750, 1750], [$statement['collected'], $statement['expenses'], $statement['available'], $statement['accounting']]);
    }

    public function test_pending_incoming_and_outgoing_only_affect_the_accounting_balance(): void
    {
        $this->pending('incoming', 80);
        $this->pending('outgoing', 30);
        $statement = $this->statement();
        $this->assertSame([0, 5000, 5000], [$statement['available'], $statement['pending'], $statement['accounting']]);
    }

    public function test_partial_and_full_settlements_move_amounts_once(): void
    {
        $incoming = $this->pending('incoming', 100);
        $this->settle($incoming, 40);
        $statement = $this->statement();
        $this->assertSame([4000, 6000, 10000], [$statement['available'], $statement['pending'], $statement['accounting']]);
        $this->settle($incoming, 60);
        $statement = $this->statement();
        $this->assertSame([10000, 0, 10000], [$statement['available'], $statement['pending'], $statement['accounting']]);

        $outgoing = $this->pending('outgoing', 25);
        $this->assertSame([10000, -2500, 7500], array_values(array_intersect_key($this->statement(), array_flip(['available', 'pending', 'accounting']))));
        $this->settle($outgoing, 25);
        $statement = $this->statement();
        $this->assertSame([7500, 0, 7500], [$statement['available'], $statement['pending'], $statement['accounting']]);
    }

    public function test_reversal_restoration_and_refunds_are_counted_once(): void
    {
        $order = $this->deliveredOrder(2000);
        $path = route('payments.store', $order);
        $this->receive($order);
        $this->postJson($path, ['action' => 'reverse', 'version' => 2, 'note' => 'Storno verificato'])->assertOk();
        $this->get('/balance')->assertViewHas('reversalTotal', 2000)->assertViewHas('refundsDue', 2000)->assertViewHas('operatingNet', 0);
        $this->assertSame(2000, $this->statement()['available'], 'Il denaro resta disponibile finché il rimborso non viene pagato.');

        $this->postJson($path, ['action' => 'refund', 'version' => 3, 'note' => 'Rimborso pagato al cliente'])->assertOk();
        $this->get('/balance')->assertViewHas('refundsDue', 0);
        $this->assertSame(0, $this->statement()['available']);

        $this->postJson($path, ['action' => 'restore', 'version' => 4, 'note' => 'Storno annullato'])->assertOk();
        $this->receive($order);
        $this->get('/balance')->assertViewHas('reversalTotal', 0)->assertViewHas('refundsDue', 0);
        $this->assertSame(2000, $this->statement()['available']);
    }

    public function test_period_filter_moves_previous_movements_into_the_opening_balance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 12:00', 'Europe/Rome'));
        $this->receive($this->deliveredOrder(5000));
        Expense::factory()->create(['user_id' => $this->admin->id, 'amount_cents' => 1000, 'spent_on' => '2026-10-10']);
        $this->travelTo(Carbon::parse('2026-11-16 12:00', 'Europe/Rome'));
        $this->receive($this->deliveredOrder(700));

        $november = $this->statement('month=2026-11');
        $this->assertSame([4000, 700, 700, 4700], [$november['opening'], $november['collected'], $november['available'], $november['closing']]);
        $october = $this->statement('month=2026-10');
        $this->assertSame([0, 4000, 4000], [$october['opening'], $october['available'], $october['closing']]);
    }

    public function test_customer_filters_are_consistent_for_one_or_more_customers(): void
    {
        [$first, $second, $third] = User::factory()->count(3)->create(['role' => UserRole::Customer]);
        $this->receive($this->deliveredOrder(1000, $first));
        $this->receive($this->deliveredOrder(2000, $second));
        $this->receive($this->deliveredOrder(4000, $third));
        $this->pending('incoming', 5, $first);

        $one = $this->statement('customer_ids[]='.$first->slug);
        $this->assertSame([1000, 500, 1500], [$one['available'], $one['pending'], $one['accounting']]);
        $two = $this->statement('customer_ids[]='.$first->slug.'&customer_ids[]='.$second->slug);
        $this->assertSame([3000, 500, 3500], [$two['available'], $two['pending'], $two['accounting']]);
        $all = $this->statement();
        $this->assertSame(7000, $all['available']);
        $this->get('/balance')->assertViewHas('cashBalance', $all['available']);
    }
}
