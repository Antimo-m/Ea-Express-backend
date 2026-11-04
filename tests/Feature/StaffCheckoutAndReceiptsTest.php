<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffCheckoutAndReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['store_name' => 'Mittente', 'sender_type' => 'private', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031', 'pickup_city' => 'Aversa', 'delivery_address' => 'Via Roma', 'delivery_street_number' => '2', 'delivery_postal_code' => '81031', 'delivery_city' => 'Aversa', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'parcel_value' => '50', 'category' => 'documents', 'package_type' => 'standard', 'urgency' => 'standard'];
    }

    public function test_staff_checkout_creates_only_on_confirmation_and_preserves_data_when_editing(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Admin]);
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'price_cents' => 500]);
        $this->actingAs($rider);
        $review = $this->post('/orders', $this->payload())->assertOk()->assertViewIs('orders.checkout')->assertSee('Conferma e crea ritiro')->viewData('review');
        $this->assertDatabaseCount('orders', 0);
        $this->post('/orders/checkout/edit', $review['data'])->assertRedirect('/orders/create')->assertSessionHasInput('parcel_value', '50');
        $data = [...$review['data'], 'checkout_token' => $review['checkout_token']];
        $this->post('/orders', [...$data, 'parcel_value' => '51'])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->post('/orders', $data)->assertRedirect();
        $this->post('/orders', $data)->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(500, Order::sole()->price_cents);
        $this->assertSame(5000, Order::sole()->parcel_value_cents);
    }

    public function test_staff_checkout_rejects_changed_tariffs_and_other_operators(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'price_cents' => 500]);
        $rider = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($rider);
        $review = $this->post('/orders', $this->payload())->assertOk()->viewData('review');
        $data = [...$review['data'], 'checkout_token' => $review['checkout_token']];
        $this->actingAs(User::factory()->create())->post('/orders', $data)->assertForbidden();
        $rate->update(['price_cents' => 600]);
        $this->actingAs($rider)->post('/orders', $data)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
    }

    public static function externalReceipts(): array
    {
        return ['equal' => ['10', 1000], 'lower' => ['8', 800], 'higher' => ['12', 1200], 'zero' => ['0', 0]];
    }

    #[DataProvider('externalReceipts')]
    public function test_external_actual_receipt_preserves_tariff_and_flows_into_balance_and_reports(string $amount, int $cents): void
    {
        $rider = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'shipping_type' => 'external', 'price_cents' => 1000, 'quoted_price_cents' => 1000, 'carrier_cost_cents' => 600]);
        $this->actingAs($rider);
        $path = '/balance/'.$order->slug.'/payment';
        $this->postJson($path, ['version' => 1, 'action' => 'receive'])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        if ($cents !== 1000) {
            $before = $order->fresh()->getAttributes();
            $this->postJson($path, ['version' => 1, 'action' => 'receive', 'received_amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
            $this->assertSame($before, $order->fresh()->getAttributes());
            $this->assertDatabaseCount('payment_entries', 0);

            return;
        }
        $this->postJson($path, ['version' => 1, 'action' => 'receive', 'received_amount' => $amount])->assertOk();
        $this->assertSame($cents, PaymentEntry::sole()->amount_cents);
        $this->assertSame(1000, $order->fresh()->price_cents);
        $this->assertSame(1000, $order->fresh()->quoted_price_cents);
        $this->assertSame(600, $order->fresh()->carrier_cost_cents);
        $this->get('/balance')->assertOk()->assertViewHas('cash', $cents)->assertViewHas('operatingNet', $cents)->assertViewHas('cashBalance', $cents)->assertDontSee('Quote vettori sugli incassi');
        $this->get('/reports')->assertOk()->assertViewHas('operatingNet', $cents)->assertViewHas('incomingTotal', $cents);
        $this->get('/orders/'.$order->slug)->assertOk()->assertSee('Incasso effettivo registrato');
        $this->postJson($path, ['version' => $order->fresh()->version, 'action' => 'reverse', 'note' => 'Errore di registrazione'])->assertOk();
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
    }

    public function test_regional_receipt_uses_tariff_and_rejects_actual_amount_override(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'price_cents' => 500]);
        $this->actingAs($rider)->postJson('/balance/'.$order->slug.'/payment', ['version' => 1, 'action' => 'receive', 'received_amount' => '8'])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->postJson('/balance/'.$order->slug.'/payment', ['version' => 1, 'action' => 'receive'])->assertOk();
        $this->assertSame(500, PaymentEntry::sole()->amount_cents);
    }

    public function test_external_order_advances_with_the_standard_buttons_without_a_carrier_handover(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'shipping_type' => 'external', 'status' => OrderStatus::Received, 'price_cents' => 1000, 'quoted_price_cents' => 1000, 'pricing_version' => 1, 'price_state' => 'agreed']);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/orders/'.$order->slug)->assertOk()->assertDontSee('Spedizione fuori regione')->assertSee('Prendi in carico');
        foreach (['accepted', 'rider_arriving', 'picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->actingAs($status === 'accepted' ? $admin : $rider);
            $this->patch('/orders/'.$order->slug, ['version' => $order->fresh()->version, 'status' => $status, 'rider_id' => $rider->slug])->assertSessionHasNoErrors();
        }
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->carrier_status);
    }

    public function test_balance_separates_recorded_net_actual_cash_and_pending_positions_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $entry = new PaymentEntry;
        $entry->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'amount_cents' => 1000, 'ea_amount_cents' => 200, 'method' => 'cash', 'note' => 'Incasso storico'])->save();
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 300, 'spent_on' => now()]);
        PendingAccount::factory()->create(['direction' => 'incoming', 'amount_cents' => 500, 'settled_cents' => 100, 'state' => 'partially_paid', 'occurred_on' => now()]);
        PendingAccount::factory()->create(['direction' => 'outgoing', 'amount_cents' => 200, 'settled_cents' => 0, 'occurred_on' => now()]);
        $this->actingAs($admin)->postJson('/movements', ['kind' => 'cash', 'amount' => '2', 'submission_key' => (string) Str::uuid()])->assertCreated();
        $this->get('/balance')->assertOk()->assertViewHas('grossIncome', 1000)->assertViewHas('totalExpenses', 300)->assertViewHas('operatingNet', 700)->assertViewHas('pendingIncoming', 400)->assertViewHas('pendingOutgoing', 200)->assertViewHas('pendingImpact', 200)->assertViewHas('cashAdditions', 200)->assertViewHas('cashBalance', 900);
    }

    public function test_cash_entries_are_separate_editable_voidable_and_not_counted_twice(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $data = ['kind' => 'cash', 'amount' => '20', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson('/movements', $data)->assertCreated();
        $this->postJson('/movements', $data)->assertCreated();
        $this->assertDatabaseCount('financial_movements', 1);
        $entry = FinancialMovement::sole();
        $this->get('/balance')->assertOk()->assertSee('Registra contanti')->assertViewHas('grossIncome', 0)->assertViewHas('cashAdditions', 2000)->assertViewHas('operatingNet', 0)->assertViewHas('cashBalance', 2000);
        $this->patchJson('/movements/'.$entry->slug, ['kind' => 'cash', 'amount' => '15', 'version' => 1])->assertOk();
        $this->get('/balance')->assertOk()->assertViewHas('cashAdditions', 1500)->assertViewHas('operatingNet', 0)->assertViewHas('cashBalance', 1500);
        $this->deleteJson('/movements/'.$entry->slug, ['version' => 2])->assertOk();
        $this->assertNotNull($entry->fresh()->voided_at);
        $this->get('/balance')->assertOk()->assertViewHas('cashAdditions', 0)->assertViewHas('operatingNet', 0)->assertViewHas('cashBalance', 0);
        $this->actingAs(User::factory()->create())->postJson('/movements', $data)->assertForbidden();
    }
}
