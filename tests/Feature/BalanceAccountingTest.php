<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_accounts_aggregate_by_account_key_without_duplicate_orders_or_payments(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $firstCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $secondCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $first = $this->deliveredOrder($firstCustomer, ['store_name' => 'Negozio Centro', 'price_cents' => 1000, 'paid_at' => now()]);
        $unpaid = $this->deliveredOrder($firstCustomer, ['store_name' => 'Mittente differente', 'price_cents' => 500]);
        $second = $this->deliveredOrder($secondCustomer, ['store_name' => 'Negozio Nord', 'price_cents' => 2000, 'paid_at' => now()]);
        $other = $this->deliveredOrder($otherCustomer, ['store_name' => 'Negozio Centro', 'price_cents' => 99999, 'paid_at' => now()]);
        $this->payment($first, $admin, 1000);
        $this->payment($second, $admin, 2000);
        $this->payment($other, $admin, 99999);
        foreach ([[$firstCustomer, 300, 450], [$secondCustomer, 200, 250]] as [$customer, $income, $expense]) {
            FinancialMovement::factory()->create(['customer_id' => $customer->id, 'user_id' => $admin->id, 'kind' => 'income', 'amount_cents' => $income]);
            $account = PendingAccount::factory()->create(['customer_id' => $customer->id, 'direction' => 'outgoing', 'amount_cents' => $expense, 'settled_cents' => $expense, 'state' => 'paid']);
            $settlement = PendingSettlement::factory()->create(['pending_account_id' => $account->id, 'user_id' => $admin->id, 'amount_cents' => $expense]);
            Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => $expense, 'pending_settlement_id' => $settlement->id]);
        }
        FinancialMovement::factory()->create(['customer_id' => $firstCustomer->id, 'user_id' => $admin->id, 'kind' => 'extra_expense', 'amount_cents' => -100]);
        FinancialMovement::factory()->create(['customer_id' => $secondCustomer->id, 'user_id' => $admin->id, 'kind' => 'adjustment_out', 'amount_cents' => -50]);
        FinancialMovement::factory()->create(['customer_id' => $firstCustomer->id, 'user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 900]);
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 99999]);
        $general = PendingAccount::factory()->create(['customer_id' => $secondCustomer->id, 'amount_cents' => 1000, 'settled_cents' => 600, 'state' => 'partially_paid']);
        PendingSettlement::factory()->create(['pending_account_id' => $general->id, 'user_id' => $admin->id, 'amount_cents' => 600]);

        $query = http_build_query(['customer_ids' => [$firstCustomer->slug, $secondCustomer->slug, $firstCustomer->slug], 'month' => '2026-10']);
        $page = $this->actingAs($admin)->get('/balance?'.$query)
            ->assertViewHas('completedCount', 3)->assertViewHas('earned', 3500)->assertViewHas('cash', 3600)
            ->assertViewHas('grossIncome', 4050)->assertViewHas('spent', 700)->assertViewHas('totalExpenses', 800)
            ->assertViewHas('extraIncome', 500)->assertViewHas('extraExpenses', 100)->assertViewHas('adjustments', -50)
            ->assertViewHas('operatingNet', 3250)->assertViewHas('pendingIncoming', 400)->assertViewHas('pendingOutgoing', 0)
            ->assertViewHas('cashAdditions', 900)->assertViewHas('finalNet', 4550)->assertViewHas('outstanding', 500)
            ->assertViewHas('selectedCustomerSlugs', [$firstCustomer->slug, $secondCustomer->slug])
            ->assertViewHas('shippingEconomics', fn (array $totals): bool => $totals['regional_count'] === 3)
            ->assertViewHas('payments', fn ($entries): bool => $entries->total() === 2)
            ->assertViewHas('orders', fn ($entries): bool => $entries->pluck('slug')->all() === [$unpaid->slug]);
        $page->assertSee('customer_ids%5B0%5D='.$firstCustomer->slug, false)
            ->assertSee('name="customer_ids[]"', false)->assertDontSee('name="customer_id"', false);

        $this->get('/balance?customer_id='.$firstCustomer->slug)->assertViewHas('completedCount', 2)
            ->assertViewHas('earned', 1500)->assertViewHas('cash', 1000)->assertViewHas('operatingNet', 750);
        $this->get('/balance')->assertViewHas('completedCount', 4)->assertViewHas('cash', 103599);
    }

    public function test_month_and_year_keep_selected_accounts_and_exclude_other_periods(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $october = $this->deliveredOrder($customer, ['price_cents' => 1000]);
        $september = $this->deliveredOrder($customer, ['price_cents' => 2000, 'delivered_at' => Carbon::parse('2026-09-30 21:59:59', 'UTC')]);
        $this->payment($october, $admin, 1000);
        $this->payment($september, $admin, 2000, '2026-09-30 21:59:59');
        $this->deliveredOrder($customer, ['price_cents' => 9000, 'delivered_at' => Carbon::parse('2025-10-01', 'UTC')]);
        $query = http_build_query(['customer_ids' => [$customer->slug], 'month' => '2026-10']);

        $this->actingAs($admin)->get('/balance?'.$query)->assertViewHas('cash', 1000)->assertViewHas('earned', 1000)->assertViewHas('completedCount', 1);
        $this->get('/balance?'.$query.'&year=2026')->assertViewHas('cash', 3000)->assertViewHas('earned', 3000)->assertViewHas('completedCount', 2);
        $this->getJson('/balance?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_reversal_uses_its_posting_period_and_restoration_never_erases_or_duplicates_cash(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->deliveredOrder($customer, ['price_cents' => 1000]);
        $path = route('payments.store', $order);
        $this->actingAs($admin)->postJson($path, ['action' => 'receive', 'version' => 1])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));

        $this->postJson($path, ['action' => 'reverse', 'version' => 2, 'note' => 'Rimborso cliente'])->assertOk();
        $query = http_build_query(['month' => '2026-10', 'customer_ids' => [$customer->slug], 'ledger' => 'reversed']);
        $this->get('/balance?'.$query)->assertViewHas('reversedCount', 1)->assertViewHas('completedCount', 0)
            ->assertViewHas('grossIncome', 0)->assertViewHas('reversalTotal', 1000)->assertViewHas('cash', -1000)->assertViewHas('operatingNet', -1000)
            ->assertViewHas('reversedOrders', fn ($rows): bool => $rows->pluck('slug')->all() === [$order->slug])
            ->assertSee('Ordini stornati')->assertSee('Rimborso cliente');
        $this->get('/balance?month=2026-09')->assertViewHas('cash', 1000)->assertViewHas('reversalTotal', 0)->assertViewHas('completedCount', 0);

        $this->postJson($path, ['action' => 'restore', 'version' => 3, 'note' => 'Ripristino concordato'])->assertOk();
        $this->assertDatabaseCount('payment_entries', 2);
        $this->assertNull($order->fresh()->receipt_voided_at);
        $this->get('/balance?'.$query)->assertViewHas('reversedCount', 0)->assertViewHas('reversalTotal', 1000)->assertViewHas('cash', -1000);
        $this->get('/balance?month=2026-09')->assertViewHas('completedCount', 1)->assertViewHas('outstanding', 1000)
            ->assertViewHas('orders', fn ($rows): bool => $rows->first()->slug === $order->slug);
        $this->postJson($path, ['action' => 'receive', 'version' => 4])->assertOk();
        $this->get('/balance?'.$query)->assertViewHas('grossIncome', 1000)->assertViewHas('reversalTotal', 1000)->assertViewHas('cash', 0)->assertViewHas('operatingNet', 0);
        $this->assertSame(1000, (int) PaymentEntry::sum('amount_cents'));
        $this->assertDatabaseCount('payment_entries', 3);
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'receipt.restore']);
    }

    public function test_an_unpaid_reversed_order_is_excluded_without_inventing_a_refund(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->deliveredOrder($customer, ['price_cents' => 1000]);

        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 1, 'note' => 'Incasso non dovuto'])->assertOk();
        $this->get('/balance?ledger=reversed')->assertViewHas('completedCount', 0)->assertViewHas('earned', 0)
            ->assertViewHas('outstanding', 0)->assertViewHas('reversedCount', 1)->assertViewHas('reversalTotal', 0)->assertViewHas('cash', 0);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_partial_monetary_reversal_does_not_mark_the_entire_order_as_reversed(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->deliveredOrder($customer, ['price_cents' => 1000]);
        $this->payment($order, $admin, 1000);
        $this->payment($order, $admin, -200);

        $this->actingAs($admin)->get('/balance?customer_ids[]='.$customer->slug)
            ->assertViewHas('grossIncome', 1000)->assertViewHas('reversalTotal', 200)->assertViewHas('cash', 800)
            ->assertViewHas('operatingNet', 800)->assertViewHas('completedCount', 1)->assertViewHas('earned', 1000)
            ->assertViewHas('reversedCount', 0)->assertViewHas('outstanding', 200);
        $this->assertNull($order->fresh()->receipt_voided_at);
    }

    public function test_multiple_account_filters_survive_independent_pagination_and_receipt_sorting(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $firstCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $secondCustomer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(11)->create(['customer_id' => $firstCustomer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 100]);
        $high = $this->deliveredOrder($secondCustomer, ['price_cents' => 900]);
        $query = http_build_query(['customer_ids' => [$firstCustomer->slug, $secondCustomer->slug], 'receipt_sort' => 'amount_high', 'orders_page' => 2]);

        $page = $this->actingAs($admin)->get('/balance?'.$query)->assertViewHas('completedCount', 12)
            ->assertViewHas('outstanding', 2000)->assertViewHas('orders', fn ($rows): bool => $rows->currentPage() === 2 && $rows->count() === 2);
        $page->assertSee('customer_ids%5B0%5D='.$firstCustomer->slug, false)
            ->assertSee('customer_ids%5B1%5D='.$secondCustomer->slug, false)
            ->assertSee('receipt_sort=amount_high', false);
        $this->get('/balance?'.http_build_query(['customer_ids' => [$firstCustomer->slug, $secondCustomer->slug], 'receipt_sort' => 'amount_high']))
            ->assertViewHas('orders', fn ($rows): bool => $rows->first()->slug === $high->slug);
    }

    public function test_pending_administration_stays_outside_cash_while_real_partial_settlements_count_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($admin)->postJson(route('pending.store'), ['subject' => 'Credito amministrativo non riscosso', 'direction' => 'incoming', 'description' => 'Servizio aggiuntivo', 'amount' => '10', 'customer_id' => $customer->slug, 'occurred_on' => '2026-10-18'])->assertOk();
        $incoming = PendingAccount::sole();
        $this->patchJson(route('pending.update', $incoming), ['action' => 'update', 'version' => 1, 'subject' => $incoming->subject, 'description' => 'Descrizione aggiornata'])->assertOk();
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('financial_movements', 0);
        $this->get('/balance')->assertViewHas('cash', 0)->assertViewHas('grossIncome', 0)->assertViewHas('pendingIncoming', 1000)
            ->assertDontSee('Credito amministrativo non riscosso')->assertDontSee('ledger=pending', false);

        $this->postJson(route('pending.settle', $incoming), ['version' => 2, 'amount' => '6', 'submission_key' => (string) Str::uuid()])->assertOk();
        $outgoing = PendingAccount::factory()->create(['customer_id' => $customer->id, 'direction' => 'outgoing', 'amount_cents' => 500]);
        $this->postJson(route('pending.settle', $outgoing), ['version' => 1, 'amount' => '3', 'submission_key' => (string) Str::uuid()])->assertOk();
        $order = $this->deliveredOrder($customer, ['price_cents' => 1000]);
        $orderPending = PendingAccount::factory()->create(['customer_id' => $customer->id, 'order_id' => $order->id, 'amount_cents' => 1000]);
        $settlement = ['version' => 1, 'amount' => '4', 'submission_key' => (string) Str::uuid()];
        $this->postJson(route('pending.settle', $orderPending), $settlement)->assertOk();
        $this->postJson(route('pending.settle', $orderPending), $settlement)->assertOk();

        $this->get('/balance?customer_ids[]='.$customer->slug)->assertViewHas('cash', 1000)->assertViewHas('grossIncome', 1000)
            ->assertViewHas('spent', 300)->assertViewHas('operatingNet', 700)->assertViewHas('pendingIncoming', 1000)->assertViewHas('pendingOutgoing', 200)
            ->assertViewHas('payments', fn ($rows): bool => $rows->total() === 1)
            ->assertViewHas('generalEntries', fn ($rows): bool => $rows->total() === 1);
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('pending_settlements', 3);
        $this->get('/pending')->assertSee('Credito amministrativo non riscosso')->assertSee('Descrizione aggiornata');
    }

    public function test_public_account_filters_reject_numeric_unknown_and_non_customer_references(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($admin);

        foreach ([(string) $customer->id, (string) Str::uuid(), $admin->slug] as $invalid) {
            $this->getJson('/balance?customer_ids[]='.$invalid)->assertUnprocessable()->assertJsonValidationErrors('customer_ids.0');
        }
        $this->getJson('/balance?customer_ids='.$customer->slug)->assertUnprocessable()->assertJsonValidationErrors('customer_ids');
        $this->getJson('/balance?customer_id='.$customer->id)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->actingAs($customer)->get('/balance?customer_ids[]='.$customer->slug)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Rider]))->get('/balance?customer_ids[]='.$customer->slug)->assertForbidden();
    }

    /** @param array<string, mixed> $attributes */
    private function deliveredOrder(User $customer, array $attributes = []): Order
    {
        return Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'shipping_type' => 'regional', 'price_state' => 'agreed', 'pricing_version' => 1, ...$attributes]);
    }

    private function payment(Order $order, User $admin, int $amount, ?string $createdAt = null): void
    {
        $payment = new PaymentEntry;
        $payment->order_id = $order->id;
        $payment->user_id = $admin->id;
        $payment->amount_cents = $amount;
        $payment->ea_amount_cents = $amount;
        $payment->method = 'cash';
        $payment->note = '';
        if ($createdAt !== null) {
            $payment->created_at = Carbon::parse($createdAt, 'UTC');
        }
        $payment->save();
    }
}
