<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_movement_categories_have_independent_server_pagination_and_keep_filters(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 3)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        FinancialMovement::factory()->count(13)->create(['user_id' => $admin->id, 'customer_id' => $customer->id, 'kind' => 'cash', 'description' => 'Cassa selezionata', 'amount_cents' => 100]);
        FinancialMovement::factory()->count(2)->create(['user_id' => $admin->id, 'customer_id' => $customer->id, 'kind' => 'income', 'description' => 'Entrata selezionata']);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'description' => 'Voce di altro cliente']);
        $query = http_build_query(['ledger' => 'cash', 'period' => 'today', 'q' => 'selezionata', 'customer_ids' => [$customer->slug], 'cash_page' => 2]);

        $response = $this->actingAs($admin)->get('/balance?'.$query);

        $response->assertOk()->assertViewHas('manualSections', fn ($sections): bool => $sections['cash']['entries']->total() === 13
            && $sections['cash']['entries']->currentPage() === 2 && $sections['cash']['entries']->count() === 3
            && $sections['cash']['entries']->every(fn ($entry): bool => $entry->kind === 'cash')
            && $sections['income']['entries']->total() === 2 && $sections['income']['entries']->currentPage() === 1
            && $sections['income']['entries']->every(fn ($entry): bool => $entry->kind === 'income'))
            ->assertSee('data-movement-category="cash"', false)->assertSee('data-movement-category="income"', false)
            ->assertSee('customer_ids%5B0%5D='.$customer->slug, false)->assertSee('q=selezionata', false)
            ->assertDontSee('Voce di altro cliente');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/finance-manual-categories.html', $response->getContent());
        }
    }

    public function test_receipts_corrections_and_paid_refunds_are_disjoint_categories(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Rettifica verificata'])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 3, 'note' => 'Rimborso già incluso nello storno'])->assertConflict();
        $this->postJson(route('payments.store', $order), ['action' => 'restore', 'version' => 3, 'note' => 'Ripristino verificato'])->assertOk();

        $response = $this->get('/balance');

        $response->assertOk()->assertViewHas('paymentSections', fn ($sections): bool => $sections['receipts']['entries']->total() === 1
            && $sections['corrections']['entries']->total() === 2 && $sections['refunds']['entries']->total() === 1
            && $sections['other']['entries']->total() === 0)
            ->assertSee('data-payment-category="receipts"', false)->assertSee('data-payment-category="corrections"', false)
            ->assertSee('data-payment-category="refunds"', false)->assertViewHas('cashMovementBalance', 0);
        $sections = $response->viewData('paymentSections');
        $slugs = collect($sections)->flatMap(fn ($section) => $section['entries']->pluck('slug'));
        $this->assertSame(4, $slugs->unique()->count());
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/finance-payment-categories.html', $response->getContent());
        }
    }

    public function test_cash_line_only_counts_explicit_cash_movements(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 3)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1500]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();

        $this->get('/balance?period=today')->assertOk()->assertViewHas('cashMovementBalance', 0)
            ->assertViewHas('grossIncome', 1500)->assertViewHas('finalNet', 1500);

        $this->postJson('/movements', ['kind' => 'cash', 'amount' => '30', 'submission_key' => (string) Str::uuid()])->assertCreated();
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 400]);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 9000, 'voided_at' => now()]);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 8000, 'occurred_on' => '2026-10-31']);

        $this->get('/balance?period=today')->assertViewHas('cashMovementBalance', 3000)
            ->assertViewHas('totalExpenses', 400)->assertViewHas('operatingResult', 1100)->assertViewHas('finalNet', 1100)
            ->assertSeeTextInOrder(['Risultato operativo', 'Sospesi', 'Contanti', 'Risultato finale']);
    }

    public function test_reversals_adjust_final_result_without_becoming_expenses_or_delivery_counts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Rettifica verificata'])->assertOk();

        $this->get('/balance')->assertOk()->assertViewHas('grossIncome', 1000)->assertViewHas('totalExpenses', 0)
            ->assertViewHas('operatingResult', 1000)->assertViewHas('finalNet', 0)->assertViewHas('cashMovementBalance', 0)
            ->assertViewHas('deliveredCount', 1)->assertViewHas('completedCount', 0)
            ->assertSee('aria-label="1 consegne effettuate nel periodo"', false);

        $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 3, 'note' => 'Denaro già restituito'])->assertConflict();
        $this->get('/balance')->assertViewHas('finalNet', 0)->assertViewHas('totalExpenses', 0)->assertViewHas('cashMovementBalance', 0)->assertViewHas('deliveredCount', 1);
    }

    public function test_open_pending_positions_are_not_added_again_when_settled(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $incoming = PendingAccount::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'direction' => 'incoming', 'amount_cents' => 1000]);
        $outgoing = PendingAccount::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'direction' => 'outgoing', 'amount_cents' => 400]);
        $this->actingAs($admin)->get('/balance')->assertViewHas('pendingImpact', 600)->assertViewHas('finalNet', 0);

        $this->postJson(route('pending.settle', $incoming), ['version' => 1, 'amount' => '2', 'method' => 'cash', 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->postJson(route('pending.settle', $outgoing), ['version' => 1, 'amount' => '1', 'method' => 'cash', 'submission_key' => (string) Str::uuid()])->assertOk();

        $this->get('/balance')->assertViewHas('pendingIncoming', 800)->assertViewHas('pendingOutgoing', 300)
            ->assertViewHas('pendingImpact', 500)->assertViewHas('grossIncome', 200)->assertViewHas('totalExpenses', 100)
            ->assertViewHas('finalNet', 100)->assertViewHas('cashMovementBalance', 0);
    }

    public function test_customer_and_period_filters_scope_all_totals_and_delivery_badge(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 3)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(3)->create(['role' => UserRole::Customer]);
        $this->actingAs($admin);
        foreach ($customers as $customer) {
            $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
            $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        }
        Order::factory()->create(['customer_id' => $customers[0]->id, 'status' => OrderStatus::Delivered, 'delivered_at' => '2026-10-31 10:00:00', 'price_cents' => 9000]);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 5000]);
        $query = http_build_query(['period' => 'today', 'customer_ids' => [$customers[0]->slug, $customers[1]->slug]]);

        $selected = $this->get('/balance?'.$query)->assertOk()->assertViewHas('grossIncome', 2000)->assertViewHas('finalNet', 2000)
            ->assertViewHas('deliveredCount', 2)->assertViewHas('cashMovementBalance', 0);
        $this->get('/balance?period=today')->assertViewHas('grossIncome', 3000)->assertViewHas('deliveredCount', 3)->assertViewHas('cashMovementBalance', 5000);
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/finance-statement-customers.html', $selected->getContent());
        }
    }
}
