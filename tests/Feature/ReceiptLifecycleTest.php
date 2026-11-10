<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\ReceiptReversal;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_twenty_euro_receipt_returns_to_pending_after_restore_and_only_recollection_adds_income(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 5)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);
        $path = route('payments.store', $order);

        $this->postJson($path, ['action' => 'receive', 'version' => 1])->assertOk();
        $original = PaymentEntry::sole();
        $originalAttributes = $original->getAttributes();
        $this->get('/balance')->assertViewHas('grossIncome', 2000)->assertViewHas('finalNet', 2000)->assertViewHas('reversalTotal', 0);
        $this->postJson($path, ['action' => 'receive', 'version' => 2])->assertUnprocessable();

        $this->postJson($path, ['action' => 'reverse', 'version' => 2, 'note' => 'Storno documentato di venti euro'])->assertOk();
        if ((int) $order->payments()->sum('cash_amount_cents') > 0) {
            $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => $order->fresh()->version, 'note' => 'Restituzione effettiva verificata separatamente'])->assertOk();
        }
        $reversal = ReceiptReversal::sole();
        $this->get('/balance')->assertViewHas('grossIncome', 0)->assertViewHas('operatingResult', 0)
            ->assertViewHas('finalNet', 0)->assertViewHas('reversalTotal', 2000)->assertViewHas('cash', 0);
        $this->postJson($path, ['action' => 'reverse', 'version' => 3, 'note' => 'Invio ripetuto'])->assertUnprocessable();

        $this->postJson($path, ['action' => 'restore', 'version' => $order->fresh()->version, 'note' => 'Riapertura senza nuovo pagamento'])->assertOk();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull($order->fresh()->receipt_voided_at);
        $this->assertNotNull($reversal->fresh()->restored_at);
        $this->assertSame(0, PaymentEntry::where('kind', 'restoration')->sole()->amount_cents);
        $this->assertSame(0, (int) $order->payments()->sum('amount_cents'));
        $this->get('/balance')->assertViewHas('grossIncome', 0)->assertViewHas('finalNet', 0)->assertViewHas('reversalTotal', 0)
            ->assertViewHas('outstanding', 2000)->assertViewHas('orders', fn ($orders): bool => $orders->contains('slug', $order->slug))
            ->assertSee('Registra incasso');
        $this->postJson($path, ['action' => 'restore', 'version' => 4, 'note' => 'Invio ripetuto'])->assertUnprocessable();

        $this->postJson($path, ['action' => 'receive', 'version' => $order->fresh()->version])->assertOk();
        $this->get('/balance')->assertViewHas('grossIncome', 2000)->assertViewHas('operatingResult', 2000)
            ->assertViewHas('finalNet', 2000)->assertViewHas('reversalTotal', 0)->assertViewHas('cash', 2000);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('receipt_reversals', 1);
        $this->assertDatabaseCount('payment_entries', 5);
        $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
        $this->assertSame(['receipt.receive', 'receipt.reverse', 'receipt.refund', 'receipt.restore', 'receipt.receive'], EconomicAudit::where('entity_type', 'orders')->where('entity_id', $order->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_active_reversal_survives_a_month_boundary_and_remains_scoped_to_the_customer(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 31)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $otherOrder = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 9000]);
        $this->actingAs($admin);
        foreach ([$order, $otherOrder] as $receipt) {
            $this->postJson(route('payments.store', $receipt), ['action' => 'receive', 'version' => 1])->assertOk();
            $this->postJson(route('payments.store', $receipt), ['action' => 'reverse', 'version' => 2, 'note' => 'Storno ancora attivo'])->assertOk();
            if ((int) $receipt->payments()->sum('cash_amount_cents') > 0) {
                $this->postJson(route('payments.store', $receipt), ['action' => 'refund', 'version' => $receipt->fresh()->version, 'note' => 'Restituzione effettiva verificata separatamente'])->assertOk();
            }
        }

        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 5)->setTime(12, 0));
        $this->get('/balance?month=2026-11&customer_ids[]='.$customer->slug)->assertViewHas('grossIncome', 0)
            ->assertViewHas('finalNet', 0)->assertViewHas('reversalTotal', 2000);
        $this->get('/balance?month=2026-11')->assertViewHas('reversalTotal', 11000);
        $this->get('/balance?month=2026-09')->assertViewHas('reversalTotal', 0);

        $this->postJson(route('payments.store', $order), ['action' => 'restore', 'version' => $order->fresh()->version, 'note' => 'Ripristino nel mese successivo'])->assertOk();
        $this->get('/balance?month=2026-11&customer_ids[]='.$customer->slug)->assertViewHas('grossIncome', 0)
            ->assertViewHas('finalNet', 0)->assertViewHas('reversalTotal', 0);
        $this->get('/balance?month=2026-11')->assertViewHas('reversalTotal', 9000);
        $this->assertDatabaseCount('receipt_reversals', 2);
    }

    public function test_receipt_reversal_restore_and_recollection_in_different_months_keep_their_registration_periods(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 15)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $path = route('payments.store', $order);
        $this->actingAs($admin)->postJson($path, ['action' => 'receive', 'version' => 1])->assertOk();
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 15)->setTime(12, 0));
        $this->postJson($path, ['action' => 'reverse', 'version' => 2, 'note' => 'Storno a ottobre'])->assertOk();
        if ((int) $order->payments()->sum('cash_amount_cents') > 0) {
            $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => $order->fresh()->version, 'note' => 'Restituzione effettiva verificata separatamente'])->assertOk();
        }
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 5)->setTime(12, 0));
        $this->postJson($path, ['action' => 'restore', 'version' => $order->fresh()->version, 'note' => 'Ripristino a novembre'])->assertOk();

        $this->get('/balance?year=2026')->assertViewHas('grossIncome', 0)->assertViewHas('finalNet', 0)->assertViewHas('reversalTotal', 0);
        $this->get('/balance?month=2026-09')->assertViewHas('grossIncome', 2000)->assertViewHas('finalNet', 2000);
        $this->get('/balance?month=2026-10')->assertViewHas('grossIncome', -2000)->assertViewHas('finalNet', -2000);
        $this->get('/balance?month=2026-11')->assertViewHas('grossIncome', 0)->assertViewHas('finalNet', 0);

        $this->travelTo(now('Europe/Rome')->setDate(2026, 12, 5)->setTime(12, 0));
        $this->postJson($path, ['action' => 'receive', 'version' => $order->fresh()->version])->assertOk();
        $this->get('/balance?month=2026-12')->assertViewHas('grossIncome', 2000)->assertViewHas('finalNet', 2000)->assertViewHas('reversalTotal', 0);
        $this->get('/balance?year=2026')->assertViewHas('grossIncome', 2000)->assertViewHas('finalNet', 2000);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_entries', 5);
    }

    public function test_repeated_reversal_cycles_only_count_the_current_active_reversal(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);
        foreach (['receive', 'reverse', 'refund', 'restore', 'receive', 'reverse', 'refund'] as $index => $action) {
            $this->postJson(route('payments.store', $order), ['action' => $action, 'version' => $index + 1, 'note' => 'Ciclo contabile verificato'])->assertOk();
        }

        $this->get('/balance')->assertViewHas('grossIncome', 0)->assertViewHas('finalNet', 0)->assertViewHas('reversalTotal', 2000);
        $this->assertDatabaseCount('receipt_reversals', 2);
        $this->assertSame(1, ReceiptReversal::whereNull('restored_at')->count());
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(0, (int) PaymentEntry::sum('cash_amount_cents'));
    }

    public function test_read_only_audit_flags_historical_income_bearing_restorations_without_rewriting_them(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $legacyRestoration = new PaymentEntry;
        $legacyRestoration->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'kind' => 'restoration', 'amount_cents' => 2000, 'ea_amount_cents' => 2000, 'cash_amount_cents' => 0, 'method' => 'cash', 'note' => 'Ripristino storico con contabilizzazione precedente'])->save();
        $before = $legacyRestoration->fresh()->getAttributes();

        $this->artisan('receipts:audit', ['--order' => $order->slug])->expectsOutputToContain('legacy_restoration')->assertFailed();

        $this->assertSame($before, $legacyRestoration->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertDatabaseCount('economic_audits', 0);
    }
}
