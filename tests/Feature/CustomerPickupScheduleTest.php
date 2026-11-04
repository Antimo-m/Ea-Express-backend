<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerPickupScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['parcel_value' => '0', 'payment_method' => 'cash', 'pickup_street_number' => '1', 'pickup_postal_code' => '80100',
            'delivery_street_number' => '2', 'delivery_postal_code' => '81100', 'package_type' => 'standard',
            'recipient_name' => 'Destinatario sintetico', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Prova',
            'pickup_city' => 'Napoli', 'delivery_address' => 'Via Test', 'delivery_city' => 'Caserta', 'pickup_date' => '2026-11-04',
            'pickup_from' => '10:37', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'clothing', 'urgency' => 'standard'];
    }

    public function test_customer_rules_publish_one_minute_lead_with_minute_precision_without_changing_staff_rules(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:35:25', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson('/api/v1/customer/booking-rules')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('timezone', 'Europe/Rome')->assertJsonPath('lead_minutes', 1)
            ->assertJsonPath('server_now', '2026-11-04T10:35:25+01:00')->assertJsonPath('minimum_pickup', '2026-11-04T10:37:00+01:00');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson('/booking-rules')
            ->assertOk()->assertJsonPath('lead_minutes', 0)->assertJsonPath('minimum_pickup', '2026-11-04T10:36:00+01:00');
    }

    public function test_direct_api_rejects_less_than_one_full_minute_and_persists_the_first_valid_start(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:35:25', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'pickup_from' => '10:36'])
            ->assertUnprocessable()->assertJsonValidationErrors('pickup_from')
            ->assertJsonPath('errors.pickup_from.0', 'Scegli un orario di ritiro almeno un minuto dopo l’ora attuale italiana.');
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/v1/customer/orders', $this->checkoutData($this->payload()))->assertCreated()
            ->assertJsonPath('data.pickup_date', '2026-11-04')->assertJsonPath('data.pickup_from', '10:37');
        $this->assertSame('10:37', substr(Order::sole()->pickup_from, 0, 5));
    }

    public function test_future_dates_keep_early_times_but_end_must_follow_start(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 18:35:25', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $data = [...$this->payload(), 'pickup_date' => '2026-11-05', 'pickup_from' => '09:00'];
        $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->assertJsonPath('data.pickup_from', '09:00');
        $this->postJson('/api/v1/customer/orders/checkout', [...$data, 'pickup_to' => '08:59'])
            ->assertUnprocessable()->assertJsonValidationErrors('pickup_to');
    }

    public function test_an_exact_minute_boundary_is_accepted_and_a_stale_checkout_does_not_create_an_order(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:35:00', 'Europe/Rome'));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $review = $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'pickup_from' => '10:36'])->assertOk()->json();
        $data = [...$review['data'], 'checkout_token' => $review['checkout_token']];
        $this->travel(1)->seconds();
        $this->postJson('/api/v1/customer/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('pickup_from');
        $this->assertDatabaseCount('orders', 0);
        try {
            app(CreateOrder::class)->handle($customer, $data);
            $this->fail('The final transaction must recheck the server time independently of HTTP validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pickup_from', $exception->errors());
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_midnight_lead_is_compared_using_full_italian_date_and_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 23:59:25', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->getJson('/api/v1/customer/booking-rules')->assertOk()->assertJsonPath('minimum_pickup', '2026-11-05T00:01:00+01:00');
        $data = [...$this->payload(), 'pickup_date' => '2026-11-05', 'pickup_to' => '00:30'];
        $this->postJson('/api/v1/customer/orders/checkout', [...$data, 'pickup_from' => '00:00'])->assertUnprocessable()->assertJsonValidationErrors('pickup_from');
        $this->postJson('/api/v1/customer/orders/checkout', [...$data, 'pickup_from' => '00:01'])->assertOk();
    }

    public function test_pickup_end_must_exist_in_the_selected_date_during_the_daylight_saving_transition(): void
    {
        $this->travelTo(Carbon::parse('2026-03-28 12:00:00', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $data = [...$this->payload(), 'pickup_date' => '2026-03-29', 'pickup_from' => '01:30', 'pickup_to' => '02:30'];

        $this->postJson('/api/v1/customer/orders/checkout', $data)->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_to')
            ->assertJsonPath('errors.pickup_to.0', 'Scegli un orario di fine ritiro valido nella data indicata, successivo all’orario iniziale.');
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/v1/customer/orders', $this->checkoutData([...$data, 'pickup_to' => '03:30']))
            ->assertCreated()->assertJsonPath('data.pickup_to', '03:30');
    }
}
