<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShippingQuoteSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $attributes = []): array
    {
        return [
            'sender_type' => 'private', 'shipping_type' => 'external', 'delivery_province' => 'TO', 'delivery_region' => 'Piemonte',
            'weight_kg' => '1', 'max_dimension_cm' => '10', 'parcel_value' => '0', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+390811234567',
            'pickup_address' => 'Via Roma', 'pickup_city' => 'Aversa', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031',
            'delivery_address' => 'Via Roma', 'delivery_city' => 'Torino', 'delivery_street_number' => '2', 'delivery_postal_code' => '10121',
            'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1,
            'category' => 'documents', 'urgency' => 'standard', 'package_type' => 'standard', ...$attributes,
        ];
    }

    private function rate(array $attributes = []): ShippingRate
    {
        return ShippingRate::factory()->create([
            'shipping_type' => 'external', 'city' => 'Torino', 'city_key' => 'torino', 'price_cents' => 700,
            'carrier_cost_cents' => 400, 'carrier_name' => 'Test carrier', 'max_weight_kg' => 5, 'max_dimension_cm' => 60, ...$attributes,
        ]);
    }

    private function customer(): User
    {
        $user = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($user, 'customer');

        return $user;
    }

    public function test_capacity_failure_or_missing_measurements_never_falls_back_to_the_general_rate(): void
    {
        $this->customer();
        $this->rate();
        $this->rate(['is_default' => true, 'city' => 'Fuori regione', 'city_key' => 'fuori regione', 'price_cents' => 100, 'max_weight_kg' => null, 'max_dimension_cm' => null]);
        foreach (['', '&weight_kg=1', '&weight_kg=6&max_dimension_cm=40', '&weight_kg=1&max_dimension_cm=61'] as $measurements) {
            $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external'.$measurements)->assertOk()->assertJsonPath('available', false)->assertJsonPath('rate_id', null);
        }
        $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external&weight_kg=5&max_dimension_cm=60')->assertJsonPath('price_cents', 700);
    }

    public function test_package_measurements_override_forged_totals_and_reject_an_overweight_checkout(): void
    {
        $this->customer();
        $this->rate();
        $package = ['weight_kg' => '50', 'length_cm' => '40', 'width_cm' => '20', 'height_cm' => '20'];
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['parcel_count' => 2, 'packages' => [$package, $package]]))
            ->assertOk()->assertJsonPath('data.weight_kg', '100.00')->assertJsonPath('data.max_dimension_cm', '40.00')->assertJsonPath('quote.available', false)->assertJsonPath('checkout_token', null);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_oversized_package_cannot_use_a_forged_smaller_dimension(): void
    {
        $this->customer();
        $this->rate();
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['packages' => [['weight_kg' => 1, 'length_cm' => 200, 'width_cm' => 10, 'height_cm' => 10]]]))
            ->assertOk()->assertJsonPath('data.max_dimension_cm', '200.00')->assertJsonPath('quote.available', false);
    }

    public function test_server_snapshot_and_package_totals_are_persisted_and_revalidated(): void
    {
        $this->customer();
        $rate = $this->rate();
        $data = $this->payload(['packages' => [['weight_kg' => 3, 'length_cm' => 40, 'width_cm' => 20, 'height_cm' => 10]]]);
        $review = $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->json();
        $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => $review['checkout_token'], 'rate_id' => 999, 'shipping_rate_id' => 999, 'rate_snapshot' => ['available' => true, 'price_cents' => 1], 'price_cents' => 1])->assertCreated();
        $order = Order::sole();
        $this->assertSame($rate->id, $order->shipping_rate_id);
        $this->assertSame(700, $order->price_cents);
        $this->assertSame('3.00', $order->weight_kg);
        $this->assertSame('40.00', $order->max_dimension_cm);
        $this->assertSame(400, $order->carrier_cost_cents);
        $review = $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->json();
        $rate->update(['max_weight_kg' => 2]);
        $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => $review['checkout_token']])->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_altering_packages_after_preview_requires_a_new_quote(): void
    {
        $this->customer();
        $this->rate();
        $data = $this->payload(['packages' => [['weight_kg' => 3, 'length_cm' => 40, 'width_cm' => 20, 'height_cm' => 10]]]);
        $review = $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->json();
        $data['packages'][0]['weight_kg'] = 6;
        $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => $review['checkout_token']])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_package_count_must_match_the_details(): void
    {
        $this->customer();
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['parcel_count' => 2, 'packages' => [['weight_kg' => 1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('packages');
    }

    public function test_zone_tampering_cannot_bypass_a_cap_specific_rate(): void
    {
        $this->customer();
        $this->rate(['postal_code' => '10121', 'zone' => 'centro', 'price_cents' => 800]);
        $this->rate(['price_cents' => 500]);
        foreach (['', '&zone=periferia', '&zone=centro'] as $zone) {
            $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external&weight_kg=1&max_dimension_cm=10'.$zone)->assertJsonPath('price_cents', 800);
        }
        $this->rate(['postal_code' => '10121', 'zone' => 'periferia', 'price_cents' => 200]);
        $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external&weight_kg=1&max_dimension_cm=10&zone=periferia')->assertJsonPath('available', false);
    }

    public function test_missing_or_unrecognized_street_cannot_select_the_cheaper_city_rate(): void
    {
        $this->customer();
        $this->rate(['postal_code' => '10121', 'street' => 'Via Roma', 'price_cents' => 800]);
        $this->rate(['price_cents' => 500]);
        $url = '/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external&weight_kg=1&max_dimension_cm=10';
        foreach (['', '&street=Via+Rona'] as $street) {
            $this->getJson($url.$street)->assertJsonPath('available', false);
        }
        $this->getJson($url.'&street=+VIA++ROMA+')->assertJsonPath('price_cents', 800);
    }

    public function test_cap_city_and_province_are_canonical_and_incompatible_combinations_are_rejected(): void
    {
        $this->customer();
        $this->rate(['shipping_type' => 'regional', 'city' => 'Napoli', 'city_key' => 'napoli', 'max_weight_kg' => null, 'max_dimension_cm' => null]);
        $this->getJson('/api/v1/customer/rates/quote?city=Napoli%20(NA)&postal_code=80121')->assertJsonPath('price_cents', 700);
        $this->getJson('/api/v1/customer/rates/quote?city=Napoli%20(NA)&postal_code=80121&province=CE')->assertJsonPath('territory_valid', false);
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['delivery_postal_code' => '80121']))->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['delivery_province' => 'NA']))->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_rejected_proposal_cannot_be_bypassed_by_editing_the_order(): void
    {
        $customer = $this->customer();
        $this->rate();
        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->json();
        $this->postJson('/api/v1/customer/orders', [...$this->payload(), 'checkout_token' => $review['checkout_token']])->assertCreated();
        $order = Order::sole();
        $order->price_state = 'rejected';
        $order->save();
        $this->postJson('/api/v1/customer/orders/'.$order->id.'/checkout', $this->payload(['version' => 1, 'weight_kg' => 2]))->assertConflict();
        $this->assertSame('rejected', $order->fresh()->price_state);
        $this->assertSame($customer->id, $order->customer_id);
    }

    public function test_quote_becomes_unusable_when_the_rate_is_disabled(): void
    {
        $this->customer();
        $rate = $this->rate();
        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->json();
        $rate->update(['active' => false]);
        $this->postJson('/api/v1/customer/orders', [...$this->payload(), 'checkout_token' => $review['checkout_token']])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_derived_package_totals_cannot_exceed_the_order_weight_limit(): void
    {
        $this->customer();
        $package = ['weight_kg' => 1000, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['parcel_count' => 11, 'packages' => array_fill(0, 11, $package)]))
            ->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        $this->assertDatabaseCount('orders', 0);
    }

    public static function incompatibleDestinations(): array
    {
        return [
            'wrong city' => [['delivery_city' => 'Napoli']],
            'wrong CAP' => [['delivery_postal_code' => '80121']],
            'wrong province' => [['delivery_province' => 'NA']],
            'wrong region' => [['delivery_region' => 'Campania']],
        ];
    }

    #[DataProvider('incompatibleDestinations')]
    public function test_incompatible_destination_cannot_create_an_order(array $attributes): void
    {
        $this->customer();
        $this->rate();
        $data = $this->payload($attributes);
        $this->postJson('/api/v1/customer/orders/checkout', $data)->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => 'forged'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    public function test_changed_rate_price_invalidates_the_checkout_token(): void
    {
        $this->customer();
        $rate = $this->rate();
        $data = $this->payload();
        $review = $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->json();
        $rate->update(['price_cents' => 900]);
        $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => $review['checkout_token'], 'price_cents' => 700])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    public function test_missing_measurements_are_allowed_only_for_the_explicit_unlimited_default(): void
    {
        $this->customer();
        $rate = $this->rate(['is_default' => true, 'city' => 'Fuori regione', 'city_key' => 'fuori regione', 'max_weight_kg' => null, 'max_dimension_cm' => null]);
        $url = '/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external';
        $this->getJson($url)->assertOk()->assertJsonPath('available', true)->assertJsonPath('price_cents', 700);
        foreach ([['max_weight_kg' => 5, 'max_dimension_cm' => null], ['max_weight_kg' => null, 'max_dimension_cm' => 60]] as $limits) {
            $rate->update($limits);
            $this->getJson($url)->assertOk()->assertJsonPath('available', false);
        }
        $rate->update(['is_default' => false, 'city' => 'Torino', 'city_key' => 'torino', 'max_weight_kg' => null, 'max_dimension_cm' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('available', false);
    }

    public function test_forged_zone_cannot_lower_the_price_saved_by_checkout(): void
    {
        $this->customer();
        $specific = $this->rate(['postal_code' => '10121', 'zone' => 'centro', 'price_cents' => 800]);
        $this->rate(['price_cents' => 500]);
        foreach ([[], ['delivery_zone' => 'periferia']] as $attributes) {
            $data = $this->payload($attributes);
            $review = $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->assertJsonPath('quote.price_cents', 800)->json();
            $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => $review['checkout_token'], 'price_cents' => 500])->assertCreated();
        }
        $this->assertDatabaseCount('orders', 2);
        foreach (Order::all() as $order) {
            $this->assertSame(800, $order->price_cents);
            $this->assertSame($specific->id, $order->shipping_rate_id);
        }
    }

    public function test_false_street_prevents_checkout_instead_of_using_the_generic_rate(): void
    {
        $this->customer();
        $this->rate(['postal_code' => '10121', 'street' => 'Via Roma', 'price_cents' => 800]);
        $this->rate(['price_cents' => 500]);
        $data = $this->payload(['delivery_address' => 'Via Milano']);
        $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->assertJsonPath('quote.available', false)->assertJsonPath('checkout_token', null);
        $this->postJson('/api/v1/customer/orders', [...$data, 'checkout_token' => 'forged'])->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
    }
}
