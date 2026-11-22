<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarrierMarginBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function externalOrder(): Order
    {
        return Order::factory()->create(['rider_id' => $this->admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'shipping_type' => 'external', 'price_cents' => 700, 'quoted_price_cents' => 700, 'carrier_cost_cents' => 600]);
    }

    public function test_direct_receipt_of_an_external_order_puts_only_the_margin_in_the_balance(): void
    {
        $order = $this->externalOrder();
        $this->actingAs($this->admin)->get('/balance')->assertOk()->assertViewHas('outstanding', 100)
            ->assertSee('Margine da registrare nel bilancio')->assertSee('Registra margine € 1,00')->assertSee('Costo vettore')
            ->assertDontSee('Importo effettivamente incassato');

        $this->postJson('/balance/'.$order->slug.'/payment', ['version' => 1, 'action' => 'receive', 'received_amount' => '7'])->assertOk();

        $entry = PaymentEntry::sole();
        $this->assertSame([700, 700, 100], [$entry->amount_cents, $entry->cash_amount_cents, $entry->ea_amount_cents]);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->get('/balance')->assertOk()->assertViewHas('cash', 100)->assertViewHas('operatingNet', 100)->assertViewHas('outstanding', 0)
            ->assertViewHas('statement', fn (array $statement): bool => $statement['collected'] === 100);
        $this->get('/balance?ledger=payments')->assertOk()->assertSee('costo vettore € 6,00', false);
        $this->get('/reports')->assertOk()->assertViewHas('operatingNet', 100)->assertViewHas('incomingTotal', 100);
    }

    public function test_reversed_and_refunded_external_receipt_returns_the_balance_to_zero(): void
    {
        $order = $this->externalOrder();
        $this->actingAs($this->admin)->postJson(route('payments.store', $order), ['version' => 1, 'action' => 'receive', 'received_amount' => '7'])->assertOk();
        $this->postJson(route('payments.store', $order), ['version' => $order->fresh()->version, 'action' => 'reverse', 'note' => 'Spedizione annullata'])->assertOk();
        $this->postJson(route('payments.store', $order), ['version' => $order->fresh()->version, 'action' => 'refund', 'note' => 'Restituiti 7 euro al cliente'])->assertOk();

        $this->get('/balance')->assertOk()->assertViewHas('cash', 0)->assertViewHas('operatingNet', 0);
    }

    public function test_pending_payments_cover_the_carrier_first_and_retries_stay_idempotent(): void
    {
        $order = $this->externalOrder();
        $account = PendingAccount::factory()->create(['order_id' => $order->id, 'direction' => 'incoming', 'amount_cents' => 700]);
        $this->actingAs($this->admin);
        $first = ['version' => 1, 'amount' => '4', 'submission_key' => (string) Str::uuid()];
        $this->postJson('/pending/'.$account->slug.'/settlements', $first)->assertOk();
        $this->postJson('/pending/'.$account->slug.'/settlements', $first)->assertOk();
        $this->postJson('/pending/'.$account->slug.'/settlements', ['version' => $account->fresh()->version, 'amount' => '3', 'submission_key' => (string) Str::uuid()])->assertOk();

        $this->assertSame([0, 100], PaymentEntry::orderBy('id')->pluck('ea_amount_cents')->all());
        $this->assertNotNull($order->fresh()->paid_at);
        $this->get('/balance')->assertOk()->assertViewHas('operatingNet', 100);
    }

    public function test_regional_receipts_keep_the_full_tariff(): void
    {
        $order = Order::factory()->create(['rider_id' => $this->admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 500]);
        $this->actingAs($this->admin)->postJson(route('payments.store', $order), ['version' => 1, 'action' => 'receive'])->assertOk();

        $this->assertSame(500, PaymentEntry::sole()->ea_amount_cents);
        $this->get('/balance')->assertOk()->assertViewHas('cash', 500);
    }

    public function test_command_reallocates_receipts_saved_with_the_full_tariff_only_when_applied(): void
    {
        $order = $this->externalOrder();
        $entry = new PaymentEntry;
        $entry->forceFill(['order_id' => $order->id, 'user_id' => $this->admin->id, 'kind' => 'receipt', 'amount_cents' => 700, 'cash_amount_cents' => 700, 'ea_amount_cents' => 700, 'method' => 'cash', 'note' => ''])->save();

        $this->artisan('payments:allocate-carrier-share')->expectsOutputToContain('Da correggere')->assertSuccessful();
        $this->assertSame(700, $entry->fresh()->ea_amount_cents);

        $this->artisan('payments:allocate-carrier-share --apply')->expectsOutputToContain('Corretto')->assertSuccessful();
        $this->assertSame(100, $entry->fresh()->ea_amount_cents);
        $this->assertTrue(EconomicAudit::where('entity_type', 'payment_entries')->where('entity_id', $entry->id)->where('action', 'payment.corrected')->exists());
        $this->actingAs($this->admin)->get('/balance')->assertOk()->assertViewHas('cash', 100);

        $this->artisan('payments:allocate-carrier-share --apply')->doesntExpectOutputToContain('Corretto')->assertSuccessful();
    }

    public function test_rider_report_counts_only_the_margin_of_external_receipts(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $order = $this->externalOrder();
        $order->forceFill(['rider_id' => $rider->id])->save();
        $this->actingAs($this->admin)->postJson(route('payments.store', $order), ['version' => $order->fresh()->version, 'action' => 'receive', 'received_amount' => '7'])->assertOk();

        $this->get(route('rider-operations.show', $rider))->assertOk()
            ->assertViewHas('balance', fn (array $balance): bool => $balance['income'] === 100 && $balance['cash'] === 100 && $balance['available'] === 100);
    }

    public function test_receive_dialog_warns_when_the_carrier_cost_is_missing(): void
    {
        $this->externalOrder()->forceFill(['carrier_cost_cents' => 0])->save();

        $this->actingAs($this->admin)->get('/balance')->assertOk()->assertSee('Costo vettore non indicato')->assertDontSee('Registra margine');
    }
}
