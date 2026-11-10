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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashMovementsTest extends TestCase
{
    use RefreshDatabase;

    public static function periods(): array
    {
        return ['today' => ['today', 10000], 'week' => ['week', 10000], 'month' => ['month', 10000], 'year' => ['year', 10000]];
    }

    #[DataProvider('periods')]
    public function test_presets_combine_with_category_search_and_selected_customer(string $period, int $expected): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        foreach ([['2026-10-28', 100], ['2026-10-26', 200], ['2026-10-01', 300], ['2026-09-01', 400], ['2025-10-28', 9000]] as [$day, $amount]) {
            PendingAccount::factory()->create(['customer_id' => $customer->id, 'subject' => 'Ritiro concordato', 'occurred_on' => $day, 'amount_cents' => $amount]);
        }
        PendingAccount::factory()->create(['customer_id' => $customer->id, 'subject' => 'Escluso dalla ricerca', 'occurred_on' => '2026-10-28', 'amount_cents' => 9000]);
        PendingAccount::factory()->create(['subject' => 'Ritiro concordato', 'occurred_on' => '2026-10-28', 'amount_cents' => 9000]);

        $query = http_build_query(['period' => $period, 'ledger' => 'pending', 'q' => 'Ritiro concordato', 'customer_ids' => [$customer->slug]]);
        $this->actingAs($admin)->get('/balance?'.$query)->assertViewHas('pendingIncoming', $expected)
            ->assertViewHas('cashBalance', 0)->assertViewHas('operatingNet', 0)
            ->assertViewHas('pendingEntries', fn ($rows): bool => $rows->sum('amount_cents') === $expected)
            ->assertSee('Movimenti di cassa')->assertSee('Residuo aperto')->assertDontSee('Escluso dalla ricerca');
    }

    public function test_explicit_custom_period_ignores_previous_year_and_month_and_requires_both_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PendingAccount::factory()->create(['subject' => 'Posizione nel periodo scelto', 'occurred_on' => '2026-10-27', 'amount_cents' => 700]);
        PendingAccount::factory()->create(['subject' => 'Posizione del vecchio periodo', 'occurred_on' => '2025-06-15', 'amount_cents' => 9000]);

        $this->actingAs($admin)->get('/balance?period=custom&from=2026-10-27&to=2026-10-28&year=2025&month=2025-06&ledger=pending')
            ->assertViewHas('period', fn ($period): bool => $period->start->toDateString() === '2026-10-27' && $period->end->toDateString() === '2026-10-28')
            ->assertViewHas('pendingIncoming', 9700)->assertSee('Posizione nel periodo scelto')->assertSee('Posizione del vecchio periodo')
            ->assertSee('name="period" value="custom"', false);
        $this->getJson('/balance?period=custom&year=2025')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/balance?period=custom&from=2026-10-27')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/balance?period=custom&to=2026-10-28')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_period_toolbar_exposes_all_presets_and_keeps_the_selected_legacy_year_on_submit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $page = $this->actingAs($admin)->get('/balance?year=2025&ledger=pending');
        foreach (['today' => 'Oggi', 'week' => 'Settimana', 'month' => 'Mese', 'year' => 'Anno'] as $mode => $label) {
            $page->assertSee('period='.$mode, false)->assertSee($label);
        }
        $page->assertSee('Personalizzato')->assertSee('name="period" value="year"', false)->assertSee('name="year" value="2025"', false);
        $this->get('/balance?period=year&year=2025&from=2025-01-01&to=2025-12-31')
            ->assertViewHas('period', fn ($period): bool => $period->start->toDateString() === '2025-01-01' && $period->end->toDateString() === '2025-12-31');
    }

    public function test_pending_category_pagination_keeps_search_account_and_custom_period_without_counting_closed_residuals(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        PendingAccount::factory()->has(PendingSettlement::factory()->state(['amount_cents' => 300]), 'settlements')->count(11)->create(['customer_id' => $customer->id, 'subject' => 'Fornitura selezionata', 'direction' => 'outgoing', 'occurred_on' => '2026-10-27', 'amount_cents' => 1000, 'settled_cents' => 300, 'state' => 'partially_paid']);
        PendingAccount::factory()->create(['customer_id' => $customer->id, 'subject' => 'Fornitura selezionata annullata', 'direction' => 'outgoing', 'occurred_on' => '2026-10-27', 'amount_cents' => 9000, 'state' => 'cancelled']);
        PendingAccount::factory()->create(['customer_id' => $customer->id, 'subject' => 'Fornitura selezionata fuori periodo', 'occurred_on' => '2026-10-26', 'amount_cents' => 9000]);
        $query = http_build_query(['period' => 'custom', 'from' => '2026-10-27', 'to' => '2026-10-28', 'ledger' => 'pending', 'q' => 'Fornitura selezionata', 'customer_ids' => [$customer->slug], 'pending_page' => 2]);

        $this->actingAs($admin)->get('/balance?'.$query)->assertViewHas('pendingOutgoing', 7700)->assertViewHas('pendingIncoming', 9000)
            ->assertViewHas('pendingEntries', fn ($rows): bool => $rows->total() === 12 && $rows->currentPage() === 2 && $rows->count() === 2)
            ->assertSee('pending_page=1', false)->assertSee('ledger=pending', false)->assertSee('from=2026-10-27', false)
            ->assertSee('q=Fornitura%20selezionata', false)->assertSee('customer_ids%5B0%5D='.$customer->slug, false)
            ->assertSee('Fornitura selezionata fuori periodo');
    }

    public function test_search_filters_posted_receipts_expenses_manual_cash_and_their_totals_on_the_server(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Cliente selezionato']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => 'Bottega Zaffiro', 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000, 'price_state' => 'agreed', 'shipping_type' => 'regional']);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        Expense::factory()->create(['user_id' => $admin->id, 'description' => 'Materiali Zaffiro', 'amount_cents' => 200, 'spent_on' => '2026-10-28']);
        Expense::factory()->create(['user_id' => $admin->id, 'description' => 'Spesa non pertinente', 'amount_cents' => 9000, 'spent_on' => '2026-10-28']);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'description' => 'Apporto Zaffiro', 'amount_cents' => 500, 'occurred_on' => '2026-10-28']);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'description' => 'Apporto non pertinente', 'amount_cents' => 9000, 'occurred_on' => '2026-10-28']);

        $this->get('/balance?period=today&q=Zaffiro&ledger=recorded')->assertViewHas('grossIncome', 1000)
            ->assertViewHas('spent', 200)->assertViewHas('cashAdditions', 500)->assertViewHas('cashBalance', 1300)
            ->assertViewHas('operatingNet', 800)->assertViewHas('recordedOrders', fn ($rows): bool => $rows->total() === 1)
            ->assertSee('Storna incasso')->assertSee('name="action" value="reverse"', false);
        $this->get('/balance?period=today&q=Zaffiro&ledger=expenses')->assertViewHas('expenses', fn ($rows): bool => $rows->total() === 1)->assertDontSee('Spesa non pertinente');
        $this->get('/balance?period=today&q=Zaffiro&ledger=cash')->assertViewHas('manualEntries', fn ($rows): bool => $rows->total() === 1)->assertDontSee('Apporto non pertinente');
        $this->getJson('/balance?period=invalid')->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->getJson('/balance?q='.str_repeat('a', 151))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/balance?from=2026-10-28&to=2026-10-27')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_cash_uses_actual_refunds_and_excludes_voided_expenses_and_manual_entries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000, 'price_state' => 'agreed', 'shipping_type' => 'regional']);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Rettifica documentata'])->assertOk();
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 300, 'occurred_on' => '2026-10-28']);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 9000, 'occurred_on' => '2026-10-28', 'voided_at' => now()]);
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 200, 'spent_on' => '2026-10-28']);
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 9000, 'spent_on' => '2026-10-28', 'voided_at' => now()]);
        $this->get('/balance?period=today')->assertViewHas('cashBalance', 1100)->assertViewHas('operatingNet', -200);

        $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 3, 'note' => 'Contanti restituiti'])->assertOk();

        $this->get('/balance?period=today')->assertViewHas('cashBalance', 100)->assertViewHas('operatingNet', -200)->assertViewHas('refundTotal', 1000);
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
        $this->assertDatabaseCount('receipt_reversals', 1);
    }
}
