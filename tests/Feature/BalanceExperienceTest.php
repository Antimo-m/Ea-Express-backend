<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_prioritizes_the_statement_and_exposes_only_the_two_existing_creation_operations(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000, 'store_name' => 'Incasso prioritario']);
        $page = $this->actingAs($admin)->get('/balance')->assertOk();
        $page->assertSeeInOrder(['Riepilogo economico del periodo', 'Prospetto contabile', 'Storni', 'id="delivery-details"', 'Incasso prioritario', 'Movimenti e storico'], false)
            ->assertSee('data-finance-go="expense"', false)->assertSee('data-finance-go="cash"', false)
            ->assertSee('data-dialog-open="receipt-'.$order->slug.'"', false)->assertSee(route('payments.store', $order), false)
            ->assertDontSee('Saldo di cassa operativo')->assertDontSee('Disponibilità contanti');
        $this->assertSame(1, substr_count($page->getContent(), 'data-dialog-open="balance-add"'));
        $this->assertSame(1, substr_count($page->getContent(), ' data-finance-wizard='));
    }

    public function test_receipt_sorting_is_server_side_stable_and_does_not_change_financial_totals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $low = Order::factory()->create(['created_by' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 100]);
        $high = Order::factory()->create(['created_by' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now()->subMinute(), 'price_cents' => 900]);
        $page = $this->actingAs($admin)->get('/balance?receipt_sort=amount_high')->assertOk();
        $this->assertSame([$high->slug, $low->slug], $page->viewData('orders')->pluck('slug')->all());
        $page->assertViewHas('outstanding', 1000)->assertViewHas('grossIncome', 0);
        $this->get('/balance?receipt_sort=amount_low')->assertOk()->assertViewHas('orders', fn ($rows): bool => $rows->first()->slug === $low->slug);
        $this->getJson('/balance?receipt_sort=price_cents;drop')->assertUnprocessable()->assertJsonValidationErrors('receipt_sort');
        $this->getJson('/balance?ledger=unknown')->assertUnprocessable()->assertJsonValidationErrors('ledger');
    }

    public function test_history_views_keep_independent_pagination_filters_voided_rows_and_public_audit_links(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(11)->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'paid_at' => now()]);
        $this->actingAs($admin)->get('/balance?ledger=recorded&recorded_page=2&customer_id='.$customer->slug)->assertOk()
            ->assertViewHas('recordedOrders', fn ($rows): bool => $rows->currentPage() === 2 && $rows->count() === 1)
            ->assertSee('ledger=recorded', false)->assertSee('recorded_page=1', false)->assertSee('customer_id='.$customer->slug, false);
        $movement = FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 1500, 'voided_at' => now()]);
        $this->get('/balance?ledger=cash')->assertOk()->assertSee('Annullata')
            ->assertSee(route('audits.index', ['type' => 'financial_movements', 'slug' => $movement->slug]));
        $this->get(route('audits.index', ['type' => 'financial_movements', 'slug' => $movement->slug]))->assertOk();
    }

    public function test_expense_and_cash_forms_keep_the_existing_validations_idempotency_and_audit_contracts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/balance')->assertOk()->assertSee(route('expenses.store'), false)->assertSee(route('movements.store'), false);
        $expense = ['description' => 'Carburante turno serale', 'amount' => '12.50', 'spent_on' => now('Europe/Rome')->toDateString(), 'submission_key' => (string) Str::uuid()];
        $this->postJson(route('expenses.store'), $expense)->assertCreated();
        $this->postJson(route('expenses.store'), $expense)->assertCreated();
        $this->assertSame(1, Expense::where('description', 'Carburante turno serale')->count());
        $this->assertSame(1250, Expense::sole()->amount_cents);
        $this->postJson(route('movements.store'), ['kind' => 'cash', 'amount' => '20', 'submission_key' => (string) Str::uuid()])->assertCreated();
        $this->get('/balance')->assertOk()->assertViewHas('spent', 1250)->assertViewHas('cashAdditions', 2000)->assertViewHas('operatingNet', -1250)->assertViewHas('finalNet', 750);
        $this->postJson(route('expenses.store'), [...$expense, 'amount' => '-1'])->assertUnprocessable();
        $entry = Expense::sole();
        $this->deleteJson(route('expenses.destroy', $entry))->assertOk()->assertJsonPath('redirect', route('balance.index'));
        $this->assertNotNull($entry->fresh()->voided_at);
        $this->assertDatabaseCount('expenses', 1);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer)->get('/balance')->assertForbidden();
        $this->postJson(route('movements.store'), ['kind' => 'cash', 'amount' => '20', 'submission_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_balance_fixtures_cover_receipts_expenses_cash_and_all_history_views(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Amministrazione EA-EXPRESS']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Azienda Test Distribuzione Nord']);
        User::factory()->create(['role' => UserRole::Customer, 'name' => 'Azienda Test Due']);
        User::factory()->create(['role' => UserRole::Customer, 'name' => 'Azienda Test Officina Digitale']);
        Order::factory()->count(12)->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'store_name' => $customer->name, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $this->actingAs($admin);
        $this->postJson(route('expenses.store'), ['description' => 'Carburante e materiali per le consegne', 'amount' => '18.50', 'spent_on' => '2026-10-18', 'submission_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson(route('movements.store'), ['kind' => 'cash', 'amount' => '40', 'submission_key' => (string) Str::uuid()])->assertCreated();
        foreach (['payments', 'expenses', 'cash', 'recorded', 'reversed', 'pending'] as $view) {
            $response = $this->get('/balance?ledger='.$view)->assertOk();
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/finance-'.$view.'.html', $response->getContent());
            }
        }
    }
}
