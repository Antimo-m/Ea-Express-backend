<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class EconomicRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['store_name' => 'Negozio test', 'sender_type' => 'business', 'recipient_name' => 'Destinatario', 'recipient_phone' => '0811234567', 'pickup_address' => 'Via Roma', 'pickup_city' => 'Napoli', 'pickup_street_number' => '1', 'pickup_postal_code' => '80100', 'delivery_address' => 'Via Milano', 'delivery_city' => 'Caserta', 'delivery_street_number' => '2', 'delivery_postal_code' => '81100', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 2, 'package_type' => 'fragile', 'category' => 'other', 'urgency' => 'standard', 'parcel_value' => '50', 'payment_method' => 'cash'];
    }

    public function test_checkout_confirms_fifty_plus_five_and_is_idempotent_and_bound_to_the_review(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'price_cents' => 500]);
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/customer/orders', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonPath('total_cents', 5500)->assertJsonPath('shipping_price_cents', 500)->json();
        $this->assertDatabaseCount('orders', 0);
        $data = [...$review['data'], 'checkout_token' => $review['checkout_token']];
        $this->postJson('/api/v1/customer/orders', [...$data, 'parcel_value' => '1'])->assertConflict();
        $result = $this->postJson('/api/v1/customer/orders', [...$data, 'price_cents' => 1])->assertCreated()->assertJsonPath('data.price_cents', 500)->assertJsonPath('data.total_cents', 5500)->assertJsonPath('data.payment.method', 'cash');
        $this->postJson('/api/v1/customer/orders', $data)->assertOk()->assertJsonPath('data.id', $result->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_mail_deliveries', 1);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->postJson('/api/v1/customer/orders', $data)->assertForbidden();
    }

    public function test_rate_crud_preserves_old_orders_and_requires_a_new_review_on_change(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rateData = ['city' => 'Caserta', 'price' => '5', 'source_reference' => 'Listino verificato', 'active' => true];
        $id = $this->actingAs($admin)->postJson('/rates', $rateData)->assertCreated()->json('data.id');
        $this->postJson('/rates', $rateData)->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->actingAs($customer, 'customer');
        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->json();
        $data = [...$review['data'], 'checkout_token' => $review['checkout_token']];
        $this->postJson('/api/v1/customer/orders', $data)->assertCreated();
        $old = Order::sole();
        $waiting = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->json();
        $newId = $this->actingAs($admin, 'web')->postJson('/rates/'.$id, [...$rateData, 'price' => '6'])->assertCreated()->json('data.id');
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', [...$waiting['data'], 'checkout_token' => $waiting['checkout_token']])->assertConflict();
        $new = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonPath('total_cents', 5600)->json();
        $this->postJson('/api/v1/customer/orders', [...$new['data'], 'checkout_token' => $new['checkout_token']])->assertCreated()->assertJsonPath('data.price_cents', 600);
        $this->assertSame(500, $old->fresh()->price_cents);
        $this->assertSame(500, $old->fresh()->rate_snapshot['price_cents']);
        $this->actingAs($admin, 'web')->postJson('/rates/'.$newId, [...$rateData, 'price' => '6', 'active' => false])->assertCreated();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonPath('checkout_token', null)->assertJsonPath('total_cents', null);
        $this->assertSame(2, Order::count());
    }

    public function test_matching_uses_specific_street_and_zone_without_fabricating_unknown_cap_prices(): void
    {
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'zone' => 'centro', 'street' => 'via milano', 'price_cents' => 700]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson('/api/v1/customer/rates/quote?city=Caserta&postal_code=81100&zone=Centro&street=Via%20Milano')->assertOk()->assertJsonPath('price_cents', 700);
        $this->getJson('/api/v1/customer/rates/quote?city=Caserta&postal_code=99999&zone=Centro&street=Via%20Milano')->assertOk()->assertJsonPath('available', false);
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'payment_method' => 'da_concordare'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_statistics_drill_down_saved_tariffs_and_bulk_print_include_every_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $orders = Order::factory()->count(40)->create(['customer_id' => $customer->id, 'status' => OrderStatus::Accepted, 'price_cents' => 500, 'parcel_count' => 3]);
        $this->actingAs($admin)->get('/stores?customer_id='.$customer->id.'&tariff=500')->assertOk()->assertViewHas('detail', fn ($d) => $d['prices'][0]->shipments === 40 && (int) $d['prices'][0]->total_cents === 20000)->assertViewHas('detailOrders', fn ($d) => $d->total() === 40)->assertSee('Spedizioni che compongono');
        $this->get('/orders/in-progress')->assertOk()->assertViewHas('currentCount', 40)->assertSee('Stampa tutte le spedizioni in corso (40)');
        $labels = $this->get('/labels?scope=all')->assertOk()->assertSee($orders->first()->reference)->assertSee($orders->last()->reference);
        $this->assertSame(40, substr_count($labels->getContent(), '<article class="label">'));
        $this->get('/labels?scope=filtered&q='.$orders->first()->reference)->assertOk()->assertViewHas('orders', fn ($o) => $o->count() === 1);
        $orders->first()->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        $this->patch('/orders/'.$orders->first()->id, ['status' => 'delivered', 'version' => 1])->assertSessionHasNoErrors();
        $this->get('/orders/in-progress')->assertOk()->assertViewHas('currentCount', 39);
        $this->actingAs(User::factory()->create())->get('/labels?scope=all')->assertOk()->assertViewHas('orders', fn ($o) => $o->isEmpty());
    }

    public function test_balance_settlements_manual_adjustments_and_expenses_reconcile_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'price_cents' => 500, 'delivered_at' => now()]);
        $this->actingAs($admin);
        $this->postJson('/pending', ['subject' => 'Cliente', 'direction' => 'incoming', 'description' => 'Spedizione', 'amount' => '5', 'order_id' => $order->id, 'customer_id' => $customer->id, 'occurred_on' => now()->toDateString()])->assertOk();
        $pending = PendingAccount::sole();
        $this->postJson('/pending/'.$pending->id.'/settlements', ['amount' => '2', 'method' => 'cash', 'submission_key' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->postJson('/movements', ['kind' => 'income', 'description' => 'Entrata accessoria', 'amount' => '10', 'occurred_on' => now()->toDateString(), 'submission_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/movements', ['kind' => 'extra_expense', 'description' => 'Costo straordinario', 'amount' => '3', 'occurred_on' => now()->toDateString(), 'submission_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/movements', ['kind' => 'adjustment_out', 'description' => 'Rettifica documentata', 'amount' => '1', 'occurred_on' => now()->toDateString(), 'submission_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/expenses', ['description' => 'Carburante', 'amount' => '1.50', 'spent_on' => now()->toDateString()])->assertCreated();
        $this->get('/balance')->assertOk()->assertViewHas('cash', 200)->assertViewHas('spent', 150)->assertViewHas('operatingNet', 650)->assertViewHas('pendingImpact', 300)->assertViewHas('finalNet', 950)->assertSee('Netto finale previsto');
        $this->postJson('/pending/'.$pending->id.'/settlements', ['amount' => '3', 'method' => 'cash', 'submission_key' => (string) Str::uuid(), 'version' => 2])->assertOk();
        $this->get('/balance')->assertViewHas('cash', 500)->assertViewHas('pendingImpact', 0)->assertViewHas('finalNet', 950);
        $this->assertSame(500, (int) PaymentEntry::sum('amount_cents'));
        $this->get('/balance?customer_id='.$customer->id)->assertViewHas('cash', 500)->assertViewHas('spent', 0)->assertViewHas('extraIncome', 0);
    }

    public function test_manual_crud_records_history_rejects_stale_writes_and_receipts_have_no_reason(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $data = ['kind' => 'income', 'description' => 'Entrata aggiuntiva', 'amount' => '10', 'occurred_on' => now()->toDateString(), 'submission_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson('/movements', $data)->assertCreated();
        $this->postJson('/movements', $data)->assertCreated();
        $movement = FinancialMovement::sole();
        $this->getJson('/movements')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/movements/'.$movement->id)->assertOk()->assertJsonPath('data.amount_cents', 1000);
        $this->patchJson('/movements/'.$movement->id, [...$data, 'amount' => '11', 'version' => 1])->assertOk();
        $this->patchJson('/movements/'.$movement->id, [...$data, 'version' => 1])->assertConflict();
        $this->deleteJson('/movements/'.$movement->id, ['version' => 2])->assertOk();
        $this->assertNotNull($movement->fresh()->voided_at);
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'financial_movements', 'entity_id' => $movement->id, 'action' => 'movement.updated', 'user_id' => $admin->id]);
        $this->get('/economic-audits?type=financial_movements&id='.$movement->id)->assertOk()->assertSee('movement.updated');
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'price_cents' => 500, 'delivered_at' => now()]);
        $this->postJson('/balance/'.$order->id.'/payment', ['action' => 'receive', 'method' => 'cash', 'version' => 1, 'note' => 'Ignora questa nota'])->assertOk();
        $this->assertSame('', PaymentEntry::sole()->note);
        $this->actingAs(User::factory()->create())->postJson('/movements', $data)->assertForbidden();
        $this->getJson('/movements')->assertForbidden();
        $this->getJson('/movements/'.$movement->id)->assertForbidden();
        $this->get('/economic-audits?type=financial_movements&id='.$movement->id)->assertForbidden();
    }

    public function test_expired_review_is_rejected_and_editing_legacy_destination_saves_the_reviewed_price(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'price_cents' => 500]);
        $this->actingAs($customer, 'customer');
        $review = $this->checkoutData($this->payload());
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/customer/orders', $review)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'pricing_version' => 0, 'price_cents' => null]);
        $data = $this->checkoutData([...$this->payload(), 'version' => 1], $order->id);
        $this->patchJson('/api/v1/customer/orders/'.$order->id, $data)->assertOk()->assertJsonPath('data.price_cents', 500)->assertJsonPath('data.total_cents', 5500);
        $this->assertSame(500, $order->fresh()->rate_snapshot['price_cents']);
    }

    public function test_customer_tariff_drill_down_does_not_expose_another_account(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(4)->create(['customer_id' => $customer->id, 'price_cents' => 500]);
        $hidden = Order::factory()->create(['price_cents' => 500]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?tariff=500')->assertOk()->assertJsonPath('shipping_spend_cents', 2000)->assertJsonPath('prices.0.shipments', 4)->assertJsonPath('detail.total', 4)->assertJsonMissing(['reference' => $hidden->reference]);
    }

    public function test_customer_balance_includes_paid_outgoing_suspensions_once_and_excludes_general_expenses(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($admin)->postJson('/pending', ['subject' => 'Rimborso cliente', 'direction' => 'outgoing', 'description' => 'Rimborso documentato', 'amount' => '10', 'customer_id' => $customer->id, 'occurred_on' => now('Europe/Rome')->toDateString()])->assertOk();
        $account = PendingAccount::sole();
        $this->postJson('/pending/'.$account->id.'/settlements', ['amount' => '4', 'method' => 'bank_transfer', 'submission_key' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->postJson('/expenses', ['description' => 'Spesa generale', 'amount' => '1', 'spent_on' => now('Europe/Rome')->toDateString()])->assertCreated();
        $this->get('/balance?customer_id='.$customer->id)->assertOk()->assertViewHas('spent', 400)->assertViewHas('pendingOutgoing', 600)->assertViewHas('finalNet', -1000);
        $this->get('/balance')->assertViewHas('spent', 500)->assertViewHas('finalNet', -1100);
    }
}
