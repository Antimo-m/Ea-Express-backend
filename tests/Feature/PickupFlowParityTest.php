<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PickupFlowParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [...['store_name' => 'Mittente di prova', 'sender_type' => 'private', 'recipient_name' => 'Destinatario di prova', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031', 'pickup_city' => 'Aversa', 'delivery_address' => 'Via Napoli', 'delivery_street_number' => '24', 'delivery_postal_code' => '81031', 'delivery_city' => 'Aversa', 'pickup_date' => '2026-11-10', 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 2, 'parcel_value' => '12,34', 'category' => 'custom', 'content_description' => 'Ricambi per automobile', 'package_type' => 'fragile', 'urgency' => 'urgent'], ...$overrides];
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function shippingServices(): array
    {
        return [
            'regional' => ['regional', []],
            'external' => ['external', ['shipping_type' => 'external', 'delivery_city' => 'Torino', 'delivery_postal_code' => '10121', 'delivery_province' => 'TO', 'delivery_region' => 'Piemonte']],
        ];
    }

    #[DataProvider('shippingServices')]
    public function test_staff_and_customer_reviews_and_confirmations_use_identical_tariffs_and_totals(string $service, array $destination): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private']);
        ShippingRate::factory()->create(['shipping_type' => $service, 'is_default' => $service === 'external', 'city' => $service === 'external' ? 'Fuori regione' : 'Aversa', 'city_key' => $service === 'external' ? 'fuori regione' : 'aversa', 'postal_code' => $service === 'external' ? null : '81031', 'price_cents' => 875, 'carrier_cost_cents' => 400]);
        $payload = $this->payload($destination);

        $staffReview = $this->actingAs($admin)->post('/orders', [...$payload, 'customer_id' => $customer->slug, 'price_cents' => 1])->assertOk()->viewData('review');
        $customerReview = $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', [...$payload, 'price_cents' => 1])->assertOk()
            ->assertJsonPath('shipping_price_cents', 875)->assertJsonPath('parcel_value_cents', 1234)->assertJsonPath('total_cents', 2109)->json();

        $this->assertSame($customerReview['quote'], $staffReview['quote']);
        $this->assertSame(875, $staffReview['shipping_price_cents']);
        $this->assertSame(1234, $staffReview['parcel_value_cents']);
        $this->assertSame(2109, $staffReview['total_cents']);
        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($admin, 'web')->post('/orders', [...$staffReview['data'], 'checkout_token' => $staffReview['checkout_token']])->assertRedirect();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', [...$customerReview['data'], 'checkout_token' => $customerReview['checkout_token']])->assertCreated()
            ->assertJsonPath('data.price_cents', 875)->assertJsonPath('data.total_cents', 2109);
        $orders = Order::orderBy('id')->get();
        foreach (['customer_id', 'shipping_type', 'shipping_rate_id', 'price_cents', 'parcel_value_cents', 'pickup_date', 'pickup_from', 'pickup_to', 'category', 'content_description', 'package_type', 'urgency', 'parcel_count'] as $field) {
            $this->assertSame($orders[0]->getRawOriginal($field), $orders[1]->getRawOriginal($field), $field);
        }
        $this->assertDatabaseCount('orders', 2);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): array
    {
        return [
            'foreign postal code' => [['delivery_postal_code' => '81100'], 'delivery_postal_code'],
            'missing address' => [['pickup_address' => ''], 'pickup_address'],
            'missing package description' => [['package_type' => 'other', 'package_description' => ''], 'package_description'],
            'missing content' => [['content_description' => ''], 'content_description'],
            'invalid count' => [['parcel_count' => 0], 'parcel_count'],
            'end before start' => [['pickup_to' => '08:00'], 'pickup_to'],
            'negative value' => [['parcel_value' => '-1'], 'parcel_value'],
            'too many decimal digits' => [['parcel_value' => '12.345'], 'parcel_value'],
            'whitespace is not a monetary conversion' => [['parcel_value' => ' 12.34 '], 'parcel_value'],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_both_http_flows_reject_the_same_invalid_booking_data(array $changes, string $field): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private']);

        $this->actingAs($admin)->postJson('/orders', $this->payload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', $this->payload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_both_flows_reject_an_end_time_that_does_not_exist_during_the_italian_clock_change(): void
    {
        $this->travelTo(Carbon::parse('2026-03-28 12:00:00', 'Europe/Rome'));
        $data = $this->payload(['pickup_date' => '2026-03-29', 'pickup_from' => '01:30', 'pickup_to' => '02:30']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->postJson('/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('pickup_to');
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->postJson('/api/v1/customer/orders/checkout', $data)->assertUnprocessable()->assertJsonValidationErrors('pickup_to');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_staff_content_suggestions_are_normalized_like_the_customer_form(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'Europe/Rome'));
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $review = $this->post('/orders', $this->payload(['category' => 'documents', 'content_description' => 'Documenti']))->assertOk()->viewData('review');

        $this->assertNull($review['data']['content_description']);
        $this->assertSame('documents', $review['data']['category']);
    }

    public function test_staff_account_selection_rejects_numeric_references_and_noncustomers(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'Europe/Rome'));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/orders', $this->payload(['customer_id' => $customer->id]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/orders', $this->payload(['customer_id' => $rider->slug]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_csv_import_does_not_trim_price_values_and_keeps_valid_cents_exact(): void
    {
        Storage::fake('local');
        $file = 'rate-import.csv';
        $header = "area,city,postal_code,price,delivery_time,source_reference\n";
        Storage::disk('local')->put($file, $header."Caserta,Aversa,81031, 8.75 ,24 ore,Test sintetico\n");

        $this->artisan('shipping:import-rates', ['file' => Storage::disk('local')->path($file), '--dry-run' => true])
            ->expectsOutputToContain('Riga 2 non valida')->assertFailed();

        $this->assertDatabaseCount('shipping_rates', 0);
        Storage::disk('local')->put($file, $header."Caserta,Aversa,81031,8.75,24 ore,Test sintetico\n");
        $this->artisan('shipping:import-rates', ['file' => Storage::disk('local')->path($file), '--dry-run' => true])
            ->expectsOutputToContain('1 tariffe valide')->assertSuccessful();
        $this->assertDatabaseCount('shipping_rates', 0);
    }

    /** @return array<string, array{string, ?int}> */
    public static function monetaryInputs(): array
    {
        return [
            'comma' => ['8,75', 875], 'point' => ['8.75', 875], 'one decimal' => ['8.7', 870], 'zero' => ['0', 0],
            'leading whitespace' => [' 8.75', null], 'trailing whitespace' => ['8.75 ', null],
            'negative' => ['-8.75', null], 'too precise' => ['8.755', null], 'exponent' => ['8e2', null],
        ];
    }

    #[DataProvider('monetaryInputs')]
    public function test_rate_prices_preserve_cents_and_reject_invalid_formats_without_trimming(string $price, ?int $cents): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->postJson('/rates', ['city' => 'Aversa', 'price' => $price, 'active' => true]);

        if ($cents === null) {
            $response->assertUnprocessable()->assertJsonValidationErrors('price');
            $this->assertDatabaseCount('shipping_rates', 0);

            return;
        }
        $response->assertCreated()->assertJsonPath('data.price_cents', $cents);
        $this->assertSame($cents, ShippingRate::sole()->price_cents);
    }
}
