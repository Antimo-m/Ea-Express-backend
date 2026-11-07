<?php

namespace Tests\Feature;

use App\Models\AccountingControl;
use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\Support\ReceiptLedger;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReceiptReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function legacyEntry(User $actor, Order $order, int $amount, string $date): PaymentEntry
    {
        $entry = new PaymentEntry;
        $entry->forceFill(['order_id' => $order->id, 'user_id' => $actor->id, 'amount_cents' => $amount, 'method' => 'cash', 'note' => 'Movimento storico da verificare', 'created_at' => $date, 'updated_at' => $date])->save();

        return $entry;
    }

    /** @return array{User, Order, PaymentEntry, PaymentEntry} */
    private function restoredLegacyReceipt(): array
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 3)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => '2026-09-16 10:00:00', 'price_cents' => 2000]);
        $receipt = $this->legacyEntry($admin, $order, 2000, '2026-09-16 12:00:00');
        $reversal = $this->legacyEntry($admin, $order, -2000, '2026-09-22 12:00:00');
        EconomicAudit::create(['user_id' => $admin->id, 'entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'receipt.restore', 'before' => ['receipt_voided_at' => '2026-09-22 12:00:00'], 'after' => ['receipt_voided_at' => null], 'created_at' => '2026-09-27 12:00:00']);

        return [$admin, $order, $receipt, $reversal];
    }

    public function test_restored_legacy_receipt_requires_explicit_verified_disposition_before_reconciliation(): void
    {
        [$admin, $order] = $this->restoredLegacyReceipt();
        $page = $this->actingAs($admin)->get('/balance?month=2026-09')->assertSee('Storico da riconciliare')->assertSee('Riconcilia incasso');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/finance-reconciliation.html', $page->getContent());
        }

        $this->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'note' => 'Verifica ricevuta'])->assertUnprocessable()->assertJsonValidationErrors(['resolution', 'confirmed']);

        $this->assertDatabaseCount('payment_entries', 2);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_retained_money_cannot_be_claimed_for_a_reversal_that_always_includes_refunding_the_customer(): void
    {
        [$admin, $order, $receipt, $reversal] = $this->restoredLegacyReceipt();
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'retained', 'confirmed' => true, 'note' => 'Lo storno attesta la restituzione'])->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $this->assertDatabaseCount('payment_entries', 2);
        $this->assertNull($reversal->fresh()->original_entry_id);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_refunded_legacy_money_remains_outstanding_until_a_real_recollection_without_duplicate_income(): void
    {
        [$admin, $order] = $this->restoredLegacyReceipt();
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'refunded', 'confirmed' => true, 'note' => 'Rimborso storico verificato sulla ricevuta'])->assertOk();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, app(ReceiptLedger::class)->cash(PaymentEntry::where('order_id', $order->id)));
        $this->assertSame(0, (int) $order->payments()->sum('amount_cents'));
        $this->get('/balance?month=2026-09')->assertViewHas('outstanding', 2000)->assertViewHas('grossIncome', 0)
            ->assertViewHas('finalNet', 0)->assertDontSee('Storico da riconciliare');

        $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 2])->assertOk();

        $recollection = $order->payments()->latest('id')->first();
        $this->assertSame('receipt', $recollection->kind);
        $this->assertSame(2000, $recollection->amount_cents);
        $this->assertSame(2000, $recollection->cash_amount_cents);
        $this->assertSame(2000, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(2000, app(ReceiptLedger::class)->cash(PaymentEntry::where('order_id', $order->id)));
        $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 3])->assertUnprocessable();
    }

    public function test_partial_receipt_status_reconciliation_never_recreates_the_amount_already_recorded(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 700, 'paid_at' => now(), 'paid_by' => $admin->id]);
        $entry = $this->legacyEntry($admin, $order, 100, now()->toDateTimeString());
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'partial', 'confirmed' => true, 'note' => 'Ricevuta verificata di un euro; residuo sei euro'])->assertOk();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertSame(100, $entry->fresh()->amount_cents);

        $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 2])->assertOk();

        $this->assertSame(700, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(600, $order->payments()->latest('id')->first()->cash_amount_cents);
        $this->assertDatabaseCount('payment_entries', 2);
    }

    public function test_balanced_historical_cycles_only_receive_missing_links_without_changing_any_amount(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 500, 'paid_at' => now()]);
        $original = $this->legacyEntry($admin, $order, 500, '2026-09-16 10:00:00');
        $reversal = $this->legacyEntry($admin, $order, -500, '2026-09-22 10:00:00');
        $this->legacyEntry($admin, $order, 500, '2026-09-25 10:00:00');

        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'links', 'confirmed' => true, 'note' => 'Ricevute originali e collegamenti verificati'])->assertOk();

        $this->assertDatabaseCount('payment_entries', 3);
        $this->assertSame($original->id, $reversal->fresh()->original_entry_id);
        $this->assertSame(500, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(500, app(ReceiptLedger::class)->cash(PaymentEntry::where('order_id', $order->id)));
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Nuovo storno tracciato'])->assertOk();
        $this->assertSame(0, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(0, app(ReceiptLedger::class)->cash(PaymentEntry::where('order_id', $order->id)));
    }

    public function test_linked_pending_payments_cannot_be_reconciled_as_an_independent_receipt(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 700, 'paid_at' => now()]);
        $this->legacyEntry($admin, $order, 100, now()->toDateTimeString());
        $pending = PendingAccount::factory()->create(['order_id' => $order->id, 'amount_cents' => 700, 'settled_cents' => 100, 'state' => 'partially_paid']);

        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'partial', 'confirmed' => true, 'note' => 'Pagamenti collegati al sospeso'])->assertConflict();

        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(100, $pending->fresh()->settled_cents);
        $this->assertDatabaseCount('payment_entries', 1);
    }

    #[TestWith(['refunded'])]
    public function test_restored_legacy_receipt_with_an_open_pending_account_is_rejected_without_compensations(string $resolution): void
    {
        [$admin, $order] = $this->restoredLegacyReceipt();
        $pending = PendingAccount::factory()->create(['order_id' => $order->id, 'amount_cents' => 2000, 'state' => 'open']);

        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => $resolution, 'confirmed' => true, 'note' => 'Verifica richiesta sui pagamenti del sospeso'])->assertConflict();

        $this->assertDatabaseCount('payment_entries', 2);
        $this->assertSame(0, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame('open', $pending->fresh()->state);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_ambiguous_links_are_rejected_without_partial_changes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 500, 'paid_at' => now()]);
        $this->legacyEntry($admin, $order, 500, '2026-09-16 10:00:00');
        $this->legacyEntry($admin, $order, 500, '2026-09-17 10:00:00');
        $this->legacyEntry($admin, $order, -500, '2026-09-22 10:00:00');

        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'links', 'confirmed' => true, 'note' => 'Verifica senza collegamento univoco'])->assertConflict();

        $this->assertSame(0, $order->payments()->whereNotNull('original_entry_id')->count());
        $this->assertDatabaseCount('payment_entries', 3);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    #[TestWith(['rider'])]
    #[TestWith(['customer'])]
    public function test_reconciliation_is_admin_only(string $role): void
    {
        [$admin, $order] = $this->restoredLegacyReceipt();
        $actor = User::factory()->create(['role' => $role, 'is_active' => true]);
        $this->actingAs($actor)->postJson(route('payments.store', $order), ['action' => 'reconcile', 'version' => 1, 'resolution' => 'retained', 'confirmed' => true, 'note' => 'Non autorizzato'])->assertForbidden();
        $this->assertDatabaseCount('payment_entries', 2);
    }

    public function test_closed_period_and_numeric_order_identifier_cannot_reconcile_history(): void
    {
        [$admin, $order] = $this->restoredLegacyReceipt();
        AccountingControl::first()->forceFill(['closed_through' => now()->toDateString()])->save();
        $data = ['action' => 'reconcile', 'version' => 1, 'resolution' => 'refunded', 'confirmed' => true, 'note' => 'Periodo chiuso'];
        $this->actingAs($admin)->postJson(route('payments.store', $order), $data)->assertConflict();
        $this->postJson('/balance/'.$order->id.'/payment', $data)->assertNotFound();
        $this->assertDatabaseCount('payment_entries', 2);
    }
}
