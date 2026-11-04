<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_requires_explicit_restore_after_reversal_before_or_after_collection(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 20000]);
        $this->actingAs($admin);
        $change = fn (string $action) => $this->postJson(route('payments.store', $order), ['action' => $action, 'version' => $order->fresh()->version, 'method' => 'cash', 'note' => 'Motivo della variazione']);

        $change('reverse')->assertOk();

        $this->assertNotNull($order->fresh()->receipt_voided_at);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->get('/balance')->assertViewHas('outstanding', 0)->assertViewHas('orders', fn ($rows) => $rows->isEmpty())->assertViewHas('reversalEntries', fn ($rows) => $rows->whereNull('restored_at')->count() === 1);
        $change('receive')->assertUnprocessable();
        $change('reverse')->assertUnprocessable();
        $change('restore')->assertOk();
        $this->get('/balance')->assertViewHas('outstanding', 20000)->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        $change('receive')->assertOk();
        $this->get('/balance')->assertViewHas('cash', 20000)->assertViewHas('recordedOrders', fn ($rows) => $rows->total() === 1);
        $change('reverse')->assertOk();
        $this->get('/balance')->assertViewHas('cash', 0)->assertViewHas('operatingNet', 0)->assertViewHas('outstanding', 0)->assertViewHas('reversalEntries', fn ($rows) => $rows->whereNull('restored_at')->count() === 1);
        $this->assertSame(-20000, PaymentEntry::where('kind', 'reversal')->latest('id')->first()->amount_cents);
        $change('reverse')->assertUnprocessable();
        $this->assertDatabaseCount('payment_entries', 3);
        $change('restore')->assertOk();
        $this->assertDatabaseCount('payment_entries', 4);
        $change('receive')->assertOk();
        $this->assertDatabaseCount('payment_entries', 5);
        $this->assertSame(20000, (int) PaymentEntry::sum('amount_cents'));
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'receipt.restore', 'user_id' => $admin->id]);
    }

    public function test_receipt_reversal_is_authorized_versioned_and_requires_a_reason(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $this->actingAs($rider)->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 1, 'note' => 'Motivo'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 99, 'note' => 'Motivo'])->assertUnprocessable();
        $this->assertNull($order->fresh()->receipt_voided_at);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_incoming_payment_corrections_recalculate_cash_and_paid_status(): void
    {
        $this->checkCorrectionScenario('incoming');
    }

    public function test_outgoing_payment_corrections_recalculate_the_linked_expense(): void
    {
        $this->checkCorrectionScenario('outgoing');
    }

    public function test_order_payment_corrections_recalculate_receipts_and_reopen_partial_balances(): void
    {
        $this->checkCorrectionScenario('incoming', true);
    }

    private function checkCorrectionScenario(string $direction, bool $linked = false): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = $linked ? Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 20000]) : null;
        $account = PendingAccount::factory()->create(['created_by' => $admin->id, 'direction' => $direction, 'amount_cents' => 20000, 'order_id' => $order?->id]);
        $this->actingAs($admin)->postJson(route('pending.settle', $account), ['version' => 1, 'amount' => '80', 'method' => 'cash', 'submission_key' => (string) Str::uuid()])->assertOk();
        $payment = PendingSettlement::sole();
        $originalDate = $payment->created_at->toDateTimeString();
        $this->assertSame(8000, $account->fresh()->settled_cents);

        foreach ([100, 80, 200, 80] as $amount) {
            $version = $account->fresh()->version;
            $this->patchJson(route('pending.settlements.update', [$account, $payment]), ['version' => $version, 'amount' => (string) $amount, 'reason' => 'Importo verificato sulla ricevuta'])->assertOk();
            $current = $account->fresh();
            $this->assertSame($amount * 100, $current->settled_cents);
            $this->assertSame((200 - $amount) * 100, $current->amount_cents - $current->settled_cents);
            $this->assertSame($amount === 200 ? 'paid' : 'partially_paid', $current->state);
            $this->assertSame($amount === 200 ? 'Saldato' : 'Parziale', $current->statusLabel());
            $this->assertSame($originalDate, $payment->fresh()->created_at->toDateTimeString());
            $this->get('/balance')->assertViewHas($direction === 'incoming' ? 'cash' : 'spent', $amount * 100);
            $this->get('/reports')->assertViewHas($direction === 'incoming' ? 'cash' : 'spent', $amount * 100);
            if ($linked) {
                $this->assertSame($amount * 100, PaymentEntry::sole()->amount_cents);
                $this->assertSame($amount === 200, $order->fresh()->paid_at !== null);
            } elseif ($direction === 'outgoing') {
                $this->assertSame($amount * 100, Expense::sole()->amount_cents);
            } elseif ($amount === 200) {
                $this->get('/balance')->assertDontSee('tone-green">Saldato', false)
                    ->assertViewHas('generalEntries', fn ($entries): bool => $entries->total() === 1 && $entries->first()->amount_cents === 20000 && $entries->first()->account->state === 'paid');
                $this->get('/pending')->assertSee('tone-green">Saldato', false);
            }
            $this->patchJson(route('pending.settlements.update', [$account, $payment]), ['version' => $version, 'amount' => '120', 'reason' => 'Invio duplicato'])->assertConflict();
        }
        $this->assertDatabaseCount('pending_settlements', 1);
        $audit = EconomicAudit::where('action', 'settlement.corrected')->latest('id')->first();
        $this->assertSame(20000, $audit->before['amount_cents']);
        $this->assertSame(8000, $audit->after['amount_cents']);
        $this->assertSame('Importo verificato sulla ricevuta', $audit->after['reason']);
    }

    public function test_payment_correction_rejects_overpayment_wrong_parent_and_unauthorized_operator(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create(['created_by' => $admin->id, 'amount_cents' => 20000]);
        $other = PendingAccount::factory()->create(['created_by' => $admin->id]);
        $this->actingAs($admin)->postJson(route('pending.settle', $account), ['version' => 1, 'amount' => '80', 'method' => 'cash', 'submission_key' => (string) Str::uuid()])->assertOk();
        $payment = PendingSettlement::sole();
        $payload = ['version' => 2, 'amount' => '201', 'reason' => 'Correzione'];

        $this->patchJson(route('pending.settlements.update', [$account, $payment]), $payload)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->patchJson(route('pending.settlements.update', [$account, $payment]), [...$payload, 'amount' => '0'])->assertUnprocessable();
        $this->patchJson(route('pending.settlements.update', [$account, $payment]), ['version' => 2, 'amount' => '100'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson(route('pending.settlements.update', [$other, $payment]), $payload)->assertNotFound();
        $this->actingAs(User::factory()->create())->patchJson(route('pending.settlements.update', [$account, $payment]), $payload)->assertForbidden();
        $this->assertSame(8000, $payment->fresh()->amount_cents);
        $this->assertSame(8000, $account->fresh()->settled_cents);
        $this->assertSame(0, EconomicAudit::where('action', 'settlement.corrected')->count());
    }

    public function test_traced_refunds_are_not_reclassified_as_unlinked_historical_reversals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1, 'method' => 'cash'])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Storno storico'])->assertOk();
        $order->refresh()->forceFill(['receipt_voided_at' => null, 'receipt_voided_by' => null, 'receipt_void_reason' => null])->save();
        $migration = require database_path('migrations/2026_09_22_133512_backfill_reversed_receipt_states.php');

        $migration->up();
        $migration->up();

        $this->assertNull($order->fresh()->receipt_voided_at);
        $this->assertNull($order->fresh()->receipt_void_reason);
        $this->assertDatabaseCount('payment_entries', 3);
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(0, EconomicAudit::where('action', 'receipt.backfilled')->count());
    }

    public function test_corrections_include_other_payments_and_rollback_if_the_linked_ledger_is_inconsistent(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 20000]);
        $account = PendingAccount::factory()->create(['created_by' => $admin->id, 'order_id' => $order->id, 'amount_cents' => 20000]);
        $this->actingAs($admin);
        foreach ([80, 60] as $amount) {
            $this->postJson(route('pending.settle', $account), ['version' => $account->fresh()->version, 'amount' => (string) $amount, 'method' => 'cash', 'submission_key' => (string) Str::uuid()])->assertOk();
        }
        $payment = PendingSettlement::first();
        $payload = ['version' => 3, 'amount' => '150', 'reason' => 'Verifica ricevuta'];
        $this->patchJson(route('pending.settlements.update', [$account, $payment]), $payload)->assertUnprocessable();
        $this->assertSame(14000, $account->fresh()->settled_cents);

        $order->forceFill(['price_cents' => 10000])->save();
        $this->patchJson(route('pending.settlements.update', [$account, $payment]), [...$payload, 'amount' => '90'])->assertConflict();
        $this->assertSame(8000, $payment->fresh()->amount_cents);
        $this->assertSame(14000, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(3, $account->fresh()->version);
        $this->assertSame(0, EconomicAudit::where('action', 'settlement.corrected')->count());
    }

    public function test_dashboard_daily_cash_matches_the_balance_including_reversals_and_manual_movements(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 22)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 20000]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['version' => 1, 'action' => 'receive', 'method' => 'cash'])->assertOk();
        $this->postJson(route('payments.store', $order), ['version' => 2, 'action' => 'reverse', 'note' => 'Annullamento'])->assertOk();
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 5000, 'spent_on' => now()->toDateString()]);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'amount_cents' => 3000, 'occurred_on' => now()->toDateString()]);

        $report = $this->get('/reports')->assertOk()->assertViewHas('operatingNet', -2000)->assertViewHas('incomingTotal', 23000)->assertViewHas('outgoingTotal', 25000);
        $this->assertSame(-2000, array_sum(array_column($report->viewData('trend'), 'incoming')) - array_sum(array_column($report->viewData('trend'), 'outgoing')));
        $this->assertCount(30, $report->viewData('trend'));
        $this->get('/balance')->assertViewHas('operatingNet', -2000);
    }

    public function test_customer_sorting_is_applied_before_pagination_and_uses_account_names(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (range(1, 31) as $index) {
            $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => sprintf('Cliente %02d', $index)]);
            Order::factory()->create(['customer_id' => $customer->id, 'store_name' => 'Nome ritiro condiviso', 'price_cents' => $index * 100]);
        }

        $this->actingAs($admin)->get('/customers?sort=name&order=asc&page=2')->assertOk()->assertViewHas('customers', fn ($rows) => $rows->total() === 31 && $rows->first()->name === 'Cliente 26');
        $this->get('/customers?sort=value&order=desc')->assertOk()->assertViewHas('customers', fn ($rows) => $rows->first()->id === $customer->id);
        $this->get('/customers?q=Nome%20ritiro')->assertViewHas('customers', fn ($rows) => $rows->isEmpty());
    }
}
