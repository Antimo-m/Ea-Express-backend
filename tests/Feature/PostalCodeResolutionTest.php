<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostalCodeResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['store_name' => 'Mittente', 'sender_type' => 'private', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031', 'pickup_city' => 'Aversa', 'delivery_address' => 'Via Roma', 'delivery_street_number' => '2', 'delivery_postal_code' => '81031', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania', 'delivery_city' => 'Aversa', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'parcel_value' => '50', 'category' => 'documents', 'package_type' => 'standard', 'urgency' => 'standard'];
    }

    public function test_listino_cap_is_checked_on_quotes_and_modified_api_requests(): void
    {
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson('/api/v1/customer/rates/quote?city=Aversa&postal_code=81031')->assertOk()->assertJsonPath('available', true)->assertJsonPath('price_cents', 500);
        $this->getJson('/api/v1/customer/rates/quote?city=Aversa&postal_code=81100')->assertOk()->assertJsonPath('available', false);
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'delivery_postal_code' => '81100'])->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_listino_cap_is_resolved_exactly_and_saved_on_the_order(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => null, 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $quote = $this->getJson('/api/v1/customer/rates/quote?city=%20AVERSA%20')->assertOk()->assertJsonPath('postal_code', '81031')->assertJsonPath('price_cents', 500)->json();
        $review = $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'delivery_postal_code' => $quote['postal_code']])->assertOk()->assertJsonPath('data.delivery_postal_code', '81031')->json();
        $this->postJson('/api/v1/customer/orders', [...$review['data'], 'checkout_token' => $review['checkout_token']])->assertCreated()->assertJsonPath('data.delivery_postal_code', '81031');
        $this->assertNull($rate->fresh()->postal_code);
        $this->assertSame('81031', Order::sole()->delivery_postal_code);
        $this->getJson('/api/v1/customer/rates/quote?city=Aversaa')->assertOk()->assertJsonPath('available', false);
    }

    public function test_multicap_municipalities_need_an_explicit_valid_cap_unless_the_listino_identifies_a_zone(): void
    {
        ShippingRate::factory()->create(['city' => 'Torino', 'city_key' => 'torino', 'postal_code' => null, 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => \App\UserRole::Admin]));
        $this->getJson('/rates/quote?city=Torino')->assertOk()->assertJsonPath('available', false);
        $this->getJson('/rates/quote?city=Torino&postal_code=10121')->assertOk()->assertJsonPath('available', true);
        $this->getJson('/rates/quote?city=Torino&postal_code=10100')->assertOk()->assertJsonPath('available', false);
        ShippingRate::factory()->create(['city' => 'Torino', 'city_key' => 'torino', 'postal_code' => '10122', 'zone' => 'centro', 'price_cents' => 600]);
        $this->getJson('/rates/quote?city=Torino&zone=centro')->assertOk()->assertJsonPath('available', false);
        $this->getJson('/rates/quote?city=Torino&zone=centro&postal_code=10122')->assertOk()->assertJsonPath('price_cents', 600);
    }

    public function test_homonyms_require_province_and_unknown_or_mismatched_localities_are_not_guessed(): void
    {
        ShippingRate::factory()->create(['city' => 'Samone', 'city_key' => 'samone', 'postal_code' => null, 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => \App\UserRole::Admin]));
        $this->getJson('/rates/quote?city=Samone')->assertOk()->assertJsonPath('available', false);
        $this->getJson('/rates/quote?city=Samone&province=TO')->assertOk()->assertJsonPath('postal_code', '10010');
        $this->getJson('/rates/quote?city=Samone&province=TN')->assertOk()->assertJsonPath('postal_code', '38059');
        $this->getJson('/rates/quote?city=Samone&province=CE')->assertOk()->assertJsonPath('available', false);
        $this->getJson('/rates/quote?city=Comune%20inesistente&postal_code=81031')->assertOk()->assertJsonPath('available', false);
    }

    public function test_address_changes_on_existing_orders_revalidate_cap_and_reject_old_reviews(): void
    {
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->postJson('/api/v1/customer/orders', $this->checkoutData($this->payload()))->assertCreated();
        $order = Order::sole();
        $data = $this->checkoutData([...$this->payload(), 'version' => 1], $order->slug);
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, [...$data, 'delivery_postal_code' => '81100'])->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, [...$data, 'delivery_address' => 'Via Verdi'])->assertConflict();
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, $this->checkoutData([...$this->payload(), 'version' => 1, 'delivery_address' => 'Via Verdi'], $order->slug))->assertOk();
        $this->assertSame('Via Verdi', $order->fresh()->delivery_address);
        $this->assertSame('81031', $order->fresh()->delivery_postal_code);
        $this->assertSame(500, $order->fresh()->price_cents);
    }
}
