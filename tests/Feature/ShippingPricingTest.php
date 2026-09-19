<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingPricingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['payment_method' => 'cash', 'recipient_name' => 'Cliente test', 'recipient_phone' => '+390811234567', 'pickup_address' => 'Via Roma', 'pickup_city' => 'Napoli', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'delivery_address' => 'Via Milano', 'delivery_city' => 'Caserta', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'other', 'urgency' => 'standard', 'package_type' => 'fragile', 'sender_type' => 'online_shop'];
    }

    public function test_quote_and_creation_use_server_price_and_preserve_historical_rate(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rate = ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/rates/quote?city=CASERTA&postal_code=81100')->assertOk()->assertJsonPath('price_cents', 500);
        $this->postJson('/api/v1/customer/orders', $this->checkoutData([...$this->payload(), 'price_cents' => 1, 'quoted_price_cents' => 1, 'price_state' => 'agreed']))->assertCreated()->assertJsonPath('data.quoted_price_cents', 500)->assertJsonPath('data.price_cents', 500)->assertJsonPath('data.package_type', 'fragile')->assertJsonPath('data.sender_type', 'online_shop');
        $order = Order::sole();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'web')->post('/rates/'.$rate->id, ['city' => 'Caserta', 'area' => 'Zona test', 'price' => '6.00', 'delivery_time' => '48 ore', 'source_reference' => 'Revisione autorizzata', 'active' => 1])->assertRedirect('/rates');
        $this->assertSame(500, $order->fresh()->quoted_price_cents);
        $this->assertSame(500, $order->fresh()->rate_snapshot['price_cents']);
        $this->assertFalse($rate->fresh()->active);
        $this->actingAs($customer, 'customer')->patchJson('/api/v1/customer/orders/'.$order->id, $this->checkoutData([...$this->payload(), 'version' => 1, 'customer_notes' => 'Al citofono'], $order->id))->assertOk()->assertJsonPath('data.quoted_price_cents', 500);
        $rider = User::factory()->create();
        $this->actingAs($rider, 'web')->patch('/orders/'.$order->id, ['version' => 2, 'status' => 'accepted', 'price' => '0.01'])->assertRedirect();
        $this->assertSame(500, $order->fresh()->price_cents);
        $this->assertDatabaseHas('economic_audits', ['action' => 'rate.revised', 'user_id' => $admin->id]);
        $this->assertSame('agreed', $order->fresh()->price_state);
    }

    public function test_unknown_ambiguous_and_cap_specific_rates_do_not_invent_a_price(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/customer/rates/quote?city=Ignoto&postal_code=99999')->assertOk()->assertJsonPath('available', false);
        ShippingRate::factory()->count(2)->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->getJson('/api/v1/customer/rates/quote?city=Caserta&postal_code=81100')->assertJsonPath('available', false);
        ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'postal_code' => '80121', 'price_cents' => 700]);
        $this->getJson('/api/v1/customer/rates/quote?city=Napoli&postal_code=99999')->assertJsonPath('available', false);
        $this->getJson('/api/v1/customer/rates/quote?city=Napoli&postal_code=80121')->assertJsonPath('price_cents', 700);
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonPath('quote.available', false)->assertJsonPath('checkout_token', null);
        $this->postJson('/api/v1/customer/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_counterproposal_requires_owner_response_and_records_both_prices(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $owner->id, 'pricing_version' => 1, 'quoted_price_cents' => 500, 'price_state' => 'awaiting_rider']);
        $this->actingAs($rider, 'web')->patchJson('/orders/'.$order->id.'/price', ['action' => 'propose', 'price' => '6.50', 'reason' => 'Consegna speciale', 'version' => 1])->assertOk();
        $this->assertSame(500, $order->fresh()->quoted_price_cents);
        $this->patchJson('/orders/'.$order->id, ['status' => 'accepted', 'version' => 2])->assertConflict();
        $this->actingAs($other, 'customer')->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'accept', 'version' => 2])->assertNotFound();
        $this->actingAs($owner, 'customer')->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'propose', 'price' => '0.01', 'reason' => 'Manipolazione', 'version' => 2])->assertForbidden();
        $this->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'accept', 'version' => 2])->assertOk();
        $this->assertSame(650, $order->fresh()->price_cents);
        $this->assertDatabaseHas('shipping_price_proposals', ['state' => 'accepted', 'responded_by' => $owner->id, 'previous_price_cents' => 500]);
        $this->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'reject', 'version' => 2])->assertConflict();
        $this->actingAs($rider, 'web')->patch('/orders/'.$order->id, ['status' => 'accepted', 'version' => 3])->assertRedirect();
        $this->assertSame(650, $order->fresh()->price_cents);
    }

    public function test_rejected_proposal_keeps_quote_and_allows_a_new_negotiation(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $owner->id, 'pricing_version' => 1, 'price_state' => 'unavailable']);
        $this->actingAs($rider)->patchJson('/orders/'.$order->id.'/price', ['action' => 'propose', 'price' => '8', 'reason' => 'Fuori zona', 'version' => 1])->assertOk();
        $this->actingAs($owner, 'customer')->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'reject', 'version' => 2])->assertOk();
        $this->assertNull($order->fresh()->price_cents);
        $this->assertSame('rejected', $order->fresh()->price_state);
        $this->actingAs($rider, 'web')->patchJson('/orders/'.$order->id.'/price', ['action' => 'propose', 'price' => '7', 'reason' => 'Nuovo accordo', 'version' => 3])->assertOk();
        $this->assertSame(2, $order->priceProposals()->count());
    }

    public function test_address_and_package_validation_and_staff_forms(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->postJson('/api/v1/customer/orders', [...$this->payload(), 'pickup_street_number' => '', 'delivery_postal_code' => '123', 'package_type' => 'other'])->assertUnprocessable()->assertJsonValidationErrors(['pickup_street_number', 'delivery_postal_code', 'package_description']);
        $rider = User::factory()->create();
        $this->actingAs($rider, 'web')->get('/orders/create')->assertOk()->assertSee('pickup_postal_code')->assertSee('online_shop')->assertDontSee('data-package-fields');
        $this->get('/rates')->assertOk();
        $this->post('/rates', ['city' => 'Napoli'])->assertForbidden();
    }

    public function test_csv_import_preserves_rate_details_without_duplicates_on_reimport(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $file = tempnam(sys_get_temp_dir(), 'ea-rates-test-');

        try {
            file_put_contents($file, <<<'CSV'
area,city,postal_code,price,delivery_time,source_reference
Zona dimostrativa,Borgo Test,,5.00,24 ore,Fixture sintetica
Zona dimostrativa,Colle Test,99999,"8,50",24/48 ore · solo venerdì,"Fixture sintetica, seconda riga"
CSV);
            $args = ['file' => $file, '--user' => $admin->id];
            $this->artisan('shipping:import-rates', $args)->assertSuccessful();
            $this->artisan('shipping:import-rates', $args)->assertSuccessful();

            $this->assertDatabaseCount('shipping_rates', 2);
            $this->assertDatabaseCount('economic_audits', 2);
            $this->assertDatabaseHas('shipping_rates', ['city' => 'Borgo Test', 'postal_code' => null, 'price_cents' => 500]);
            $this->assertDatabaseHas('shipping_rates', ['city' => 'Colle Test', 'postal_code' => '99999', 'price_cents' => 850, 'delivery_time' => '24/48 ore · solo venerdì', 'source_reference' => 'Fixture sintetica, seconda riga']);
        } finally {
            unlink($file);
        }
    }
}
