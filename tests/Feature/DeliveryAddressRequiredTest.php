<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryAddressRequiredTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(?string $service = 'regional'): array
    {
        $data = [
            'store_name' => 'Mittente', 'sender_type' => 'private',
            'recipient_name' => 'Destinatario', 'recipient_phone' => '+390000000000',
            'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031', 'pickup_city' => 'Aversa',
            'delivery_address' => 'Via Napoli', 'delivery_street_number' => '2',
            'delivery_city' => 'Aversa', 'delivery_postal_code' => '81031', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania',
            'pickup_date' => now('Europe/Rome')->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00',
            'parcel_count' => 1, 'parcel_value' => '0', 'category' => 'documents', 'package_type' => 'standard', 'urgency' => 'standard',
        ];

        if ($service !== null) {
            $data['shipping_type'] = $service;
        }
        if ($service === 'external') {
            $data = [...$data, 'delivery_city' => 'Torino', 'delivery_postal_code' => '10121', 'delivery_province' => 'Torino', 'delivery_region' => 'Piemonte'];
        }

        return $data;
    }

    /** @return array<string, array{?string, array<string, ?string>, list<string>}> */
    public static function incompleteDestinations(): array
    {
        $cases = [];
        $fields = ['delivery_postal_code', 'delivery_province', 'delivery_region'];
        foreach (['regional', 'external', null] as $service) {
            foreach ($fields as $field) {
                $cases[($service ?? 'unspecified').' missing '.$field] = [$service, [], [$field]];
            }
            foreach (['empty' => '', 'null' => null, 'whitespace' => '   '] as $name => $value) {
                $cases[($service ?? 'unspecified').' '.$name] = [$service, array_fill_keys($fields, $value), $fields];
            }
        }

        return $cases;
    }

    #[DataProvider('incompleteDestinations')]
    public function test_staff_and_customer_reject_incomplete_delivery_locations_with_422(?string $service, array $overrides, array $fields): void
    {
        $payload = $this->payload($service);
        foreach ($fields as $field) {
            unset($payload[$field]);
        }
        $payload = [...$payload, ...$overrides];
        $messages = [
            'delivery_postal_code' => 'Il campo CAP di consegna è obbligatorio.',
            'delivery_province' => 'Il campo provincia di consegna è obbligatorio.',
            'delivery_region' => 'Il campo regione di consegna è obbligatorio.',
        ];
        $errors = array_intersect_key($messages, array_flip($fields));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private']);

        $this->actingAs($admin)->postJson('/orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->postJson('/api/v1/customer/orders', [...$payload, 'checkout_token' => 'alterato'])
            ->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_customer_cannot_clear_delivery_location_when_editing_an_order(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'delivery_postal_code' => '81031', 'delivery_city' => 'Aversa',
            'delivery_province' => 'Caserta', 'delivery_region' => 'Campania',
        ]);
        $payload = [...$this->payload(), 'version' => $order->version, 'delivery_postal_code' => '', 'delivery_province' => '', 'delivery_region' => ''];
        $this->actingAs($customer, 'customer');

        $this->postJson('/api/v1/customer/orders/'.$order->slug.'/checkout', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery_postal_code', 'delivery_province', 'delivery_region']);
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, [...$payload, 'checkout_token' => 'alterato'])
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery_postal_code', 'delivery_province', 'delivery_region']);

        $this->assertSame('81031', $order->fresh()->delivery_postal_code);
        $this->assertSame('Caserta', $order->delivery_province);
        $this->assertSame('Campania', $order->delivery_region);
        $this->assertSame(1, $order->version);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_staff_form_marks_all_delivery_location_fields_as_required(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/orders/create')->assertOk();
        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);

        foreach (['delivery_postal_code', 'delivery_province', 'delivery_region'] as $field) {
            $this->assertTrue($document->getElementById($field)->hasAttribute('required'), $field);
        }
    }
}
