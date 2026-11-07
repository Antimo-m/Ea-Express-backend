<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['shipping_type' => 'external', 'delivery_province' => 'Torino', 'delivery_region' => 'Piemonte',
            'store_name' => 'Mittente prova', 'sender_type' => 'private', 'recipient_name' => 'Destinatario prova', 'recipient_phone' => '+390000000000',
            'pickup_address' => 'Via Prova', 'pickup_street_number' => '1', 'pickup_postal_code' => '80100', 'pickup_city' => 'Napoli',
            'delivery_address' => 'Via Test', 'delivery_street_number' => '2', 'delivery_postal_code' => '10121', 'delivery_city' => 'Torino',
            'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00',
            'parcel_count' => 2, 'parcel_value' => '100', 'category' => 'custom', 'content_description' => 'Libri', 'package_type' => 'standard', 'urgency' => 'standard'];
    }

    public function test_customer_checkout_without_measurements_creates_only_after_confirmation(): void
    {
        $this->freezeTime();
        $this->artisan('shipping:initialize-external-rate')->assertSuccessful();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer');

        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonPath('shipping_price_cents', 700)->json();
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/v1/customer/orders', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/v1/customer/orders', [...$review['data'], 'checkout_token' => $review['checkout_token']])->assertCreated()->assertJsonPath('data.price_cents', 700);
        $this->assertDatabaseHas('orders', ['shipping_type' => 'external', 'weight_kg' => null, 'max_dimension_cm' => null, 'parcel_count' => 2, 'content_description' => 'Libri', 'quoted_price_cents' => 700]);
    }

    public function test_staff_and_customer_use_revised_external_rate_without_measurements(): void
    {
        $this->freezeTime();
        $this->artisan('shipping:initialize-external-rate')->assertSuccessful();
        $rate = ShippingRate::where('is_default', true)->sole();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $url = '/rates/quote?city=Torino&postal_code=10121&shipping_type=external';
        $this->getJson($url)->assertOk()->assertJsonPath('price_cents', 700);
        $this->post('/orders', $this->staffCheckoutData($this->payload()))->assertSessionHasNoErrors()->assertRedirect();
        $old = Order::sole();
        $this->postJson('/rates/'.$rate->slug, ['shipping_type' => 'external', 'city' => 'Fuori regione', 'price' => '8', 'carrier_cost' => '6', 'active' => true, 'source_reference' => 'Revisione prova'])->assertCreated();

        $this->getJson($url)->assertOk()->assertJsonPath('price_cents', 800);
        $this->post('/orders', $this->staffCheckoutData($this->payload()))->assertSessionHasNoErrors()->assertRedirect();
        $new = Order::latest('id')->firstOrFail();
        $this->assertSame(800, $new->quoted_price_cents);
        $this->assertNotSame($old->shipping_rate_id, $new->shipping_rate_id);
        $this->assertSame(700, $old->fresh()->quoted_price_cents);
        $this->assertSame('external', $new->shipping_type);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonPath('shipping_price_cents', 800);
    }

    public function test_staff_regional_quote_selects_zone_and_persists_the_same_rate(): void
    {
        $this->freezeTime();
        ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'price_cents' => 400]);
        $rate = ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'postal_code' => '80121', 'zone' => 'Centro', 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/rates/quote?city=Napoli&postal_code=80121&zone=Centro&shipping_type=regional')->assertOk()->assertJsonPath('rate_slug', $rate->slug)->assertJsonMissingPath('rate_id')->assertJsonPath('price_cents', 500);

        $this->post('/orders', $this->staffCheckoutData([...$this->payload(), 'shipping_type' => 'regional', 'delivery_city' => 'Napoli', 'delivery_province' => 'NA', 'delivery_region' => 'Campania', 'delivery_postal_code' => '80121', 'delivery_zone' => 'Centro']))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('orders', ['shipping_type' => 'regional', 'shipping_rate_id' => $rate->id, 'quoted_price_cents' => 500, 'delivery_zone' => 'Centro']);
    }
}
