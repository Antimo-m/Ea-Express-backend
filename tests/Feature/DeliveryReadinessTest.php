<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_receipts_do_not_exhaust_the_financial_write_budget(): void
    {
        $rider = User::factory()->create(['role' => \App\UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1000]);
        $this->actingAs($rider);
        for ($i = 0; $i < 60; $i++) {
            $this->patchJson('/messages/'.$order->slug.'/read')->assertOk();
        }
        $this->patchJson('/messages/'.$order->slug.'/read')->assertTooManyRequests();
        $this->postJson('/balance/'.$order->slug.'/payment', ['version' => 1, 'action' => 'receive', 'note' => 'POS', 'method' => 'card'])->assertOk()->assertJsonPath('message', 'Movimento registrato nel bilancio.');
        $this->assertDatabaseHas('payment_entries', ['order_id' => $order->id, 'method' => 'cash', 'amount_cents' => 1000]);
    }

    public function test_expense_retries_create_one_record_and_native_form_redirects_to_balance(): void
    {
        $rider = User::factory()->create(['role' => \App\UserRole::Admin]);
        $payload = ['submission_key' => (string) Str::uuid(), 'description' => 'Parcheggio', 'amount' => '4.50', 'spent_on' => now('Europe/Rome')->toDateString()];
        $this->actingAs($rider)->postJson('/expenses', $payload)->assertCreated();
        $this->postJson('/expenses', $payload)->assertCreated();
        $this->postJson('/expenses', [...$payload, 'amount' => '5'])->assertConflict();
        $this->post('/expenses', $payload)->assertRedirect(route('balance.index'))->assertSessionHas('status', 'Spesa registrata.');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseHas('expenses', ['user_id' => $rider->id, 'amount_cents' => 450]);
    }

    public function test_retired_agreement_endpoints_preserve_historical_fields_without_new_messages(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted, 'payment_method' => 'card', 'payment_confirmed_at' => now()]);
        $this->actingAs($customer, 'customer')->patchJson('/api/v1/customer/orders/'.$order->slug.'/payment-agreement', ['action' => 'propose', 'method' => 'cash', 'version' => 1])->assertNotFound();
        $this->actingAs($rider, 'web')->patchJson('/orders/'.$order->slug.'/payment-agreement', ['action' => 'confirm', 'version' => 1])->assertNotFound();
        $this->assertDatabaseCount('order_messages', 0);
        $this->assertSame('card', $order->fresh()->payment_method);
        $this->assertNull($order->fresh()->paid_at);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertJsonMissingPath('data.payment')->assertJsonPath('data.receipt.state', 'unrecorded');
    }

    public function test_payment_agreement_rejects_other_customers_riders_and_stale_versions(): void
    {
        $order = Order::factory()->create(['customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'rider_id' => User::factory(), 'status' => OrderStatus::Accepted]);
        $data = ['action' => 'propose', 'method' => 'cash', 'version' => 1];
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->patchJson('/api/v1/customer/orders/'.$order->slug.'/payment-agreement', $data)->assertNotFound();
        $this->actingAs(User::factory()->create(), 'web')->patchJson('/orders/'.$order->slug.'/payment-agreement', $data)->assertNotFound();
        $this->actingAs($order->rider, 'web')->patchJson('/orders/'.$order->slug.'/payment-agreement', [...$data, 'version' => 2])->assertNotFound();
        $this->assertNull($order->fresh()->payment_method);
        $this->assertDatabaseCount('order_messages', 0);
    }

    public function test_message_retry_is_idempotent_and_cannot_replace_the_original_body(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $payload = ['submission_key' => (string) Str::uuid(), 'body' => 'Preferisco pagare in contanti'];
        $path = '/api/v1/customer/orders/'.$order->slug.'/messages';
        $this->actingAs($customer, 'customer')->postJson($path, $payload)->assertCreated();
        $this->postJson($path, $payload)->assertCreated();
        $this->postJson($path, [...$payload, 'body' => 'Altro'])->assertConflict();
        $this->assertDatabaseCount('order_messages', 1);
    }

    public function test_delivery_preference_is_ignored_in_new_orders(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->make();
        $payload = $order->only(['recipient_name', 'recipient_phone', 'pickup_address', 'pickup_city', 'delivery_address', 'delivery_city', 'parcel_count', 'category', 'urgency']);
        $payload += ['parcel_value' => '50', 'payment_method' => 'cash', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania', 'package_type' => 'standard', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00'];
        $this->actingAs($customer, 'customer');
        ShippingRate::factory()->create(['city' => $payload['delivery_city'], 'city_key' => ShippingQuote::cityKey($payload['delivery_city'])]);
        $this->postJson('/api/v1/customer/orders', $this->checkoutData([...$payload, 'delivery_window' => '16:30']))->assertCreated()->assertJsonPath('data.delivery_window', null);
        $this->assertDatabaseHas('orders', ['customer_id' => $customer->id, 'delivery_window' => null]);
    }

    public function test_native_expense_save_never_redirects_to_a_background_json_endpoint(): void
    {
        $rider = User::factory()->create(['role' => \App\UserRole::Admin]);
        $this->actingAs($rider)->get('/balance')->assertOk();
        $this->getJson('/notifications/feed')->assertOk();
        $this->post('/expenses', ['description' => 'Parcheggio', 'amount' => '2', 'spent_on' => now('Europe/Rome')->toDateString()])
            ->assertRedirect(route('balance.index'))->assertSessionHas('status', 'Spesa registrata.');
        $this->assertDatabaseCount('expenses', 1);
    }
}
