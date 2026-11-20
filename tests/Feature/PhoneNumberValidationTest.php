<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\Support\PhoneNumber;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneNumberValidationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{valid: array<string, string>, invalid: list<string>} */
    private function vectors(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/phone-numbers.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function payload(mixed $phone): array
    {
        return ['parcel_value' => '50', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania', 'package_type' => 'standard', 'recipient_name' => 'Mario Rossi', 'recipient_phone' => $phone, 'pickup_address' => 'Via Roma 1', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Milano 2', 'delivery_city' => 'Caserta', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'clothing', 'urgency' => 'standard'];
    }

    public function test_shared_vectors_are_sanitized_normalized_and_validated(): void
    {
        $vectors = $this->vectors();
        foreach ($vectors['valid'] as $input => $normalized) {
            $this->assertSame($normalized, PhoneNumber::normalize((string) $input), 'Valido: '.$input);
            $this->assertSame($normalized, PhoneNumber::normalize($normalized), 'Idempotente: '.$normalized);
        }
        foreach ($vectors['invalid'] as $input) {
            $this->assertNull(PhoneNumber::normalize($input), 'Non valido: '.$input);
        }
        $this->assertNull(PhoneNumber::normalize(['3331234567']));
        $this->assertNull(PhoneNumber::normalize(3331234567));
    }

    public function test_customer_api_rejects_invalid_numbers_with_a_clear_message(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        foreach (['0', '20', '123', '0000000000', '1111111111', '333 12a 4567', '+39 0000000000'] as $phone) {
            $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', $this->payload($phone))
                ->assertUnprocessable()->assertJsonPath('errors.recipient_phone.0', PhoneNumber::Message);
        }
        $this->postJson('/api/v1/customer/orders/checkout', $this->payload(['3331234567']))->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        $this->postJson('/api/v1/customer/orders', [...$this->payload('0000000000'), 'checkout_token' => 'manipolato'])->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        $this->assertSame(0, Order::count());
    }

    public function test_valid_numbers_are_stored_in_a_single_normalized_format(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $review = $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', $this->payload('333 123 4567'))->assertOk()->json();
        $this->assertSame('+39 3331234567', $review['data']['recipient_phone']);
        $this->postJson('/api/v1/customer/orders', [...$this->payload('333-123-4567'), 'checkout_token' => $review['checkout_token']])->assertCreated()->assertJsonPath('data.recipient_phone', '+39 3331234567');
        $this->assertSame('+39 3331234567', Order::sole()->recipient_phone);
    }

    public function test_staff_booking_applies_the_same_rule(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $data = [...$this->payload('0000000000'), 'customer_id' => $customer->slug, 'store_name' => 'Negozio test', 'sender_type' => 'business'];
        $this->actingAs($admin)->post('/orders', $data)->assertSessionHasErrors(['recipient_phone' => PhoneNumber::Message]);
        $review = $this->actingAs($admin)->post('/orders', [...$data, 'recipient_phone' => '+44 20 7946 0958'])->assertOk()->viewData('review');
        $this->assertSame('+442079460958', $review['data']['recipient_phone']);
    }
}
