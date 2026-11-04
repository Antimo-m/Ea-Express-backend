<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SenderAddress;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SenderAddressTest extends TestCase
{
    use RefreshDatabase;

    private function address(): array
    {
        return ['version' => 0, 'pickup_address' => 'Via Roma', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'pickup_city' => 'Napoli'];
    }

    public function test_customer_can_save_reuse_edit_and_delete_without_changing_booked_orders(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/sender-address')->assertOk()->assertJsonPath('data', null);
        $this->putJson('/api/v1/customer/sender-address', $this->address())->assertOk()->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.customer_id');
        $saved = $this->getJson('/api/v1/customer/sender-address')->assertOk()->json('data');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $payload = [...$saved, 'parcel_value' => '0', 'recipient_name' => 'Cliente finale', 'recipient_phone' => '+390000000000', 'delivery_address' => 'Via Milano', 'delivery_street_number' => '25', 'delivery_postal_code' => '81100', 'delivery_city' => 'Caserta', 'package_type' => 'standard', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'clothing', 'urgency' => 'standard'];
        $this->postJson('/api/v1/customer/orders', $this->checkoutData($payload))->assertCreated()->assertJsonPath('data.pickup_street_number', '10');
        $order = Order::sole();
        $this->putJson('/api/v1/customer/sender-address', [...$this->address(), 'version' => 1, 'pickup_street_number' => '20'])->assertOk()->assertJsonPath('data.pickup_street_number', '20');
        $this->getJson('/api/v1/customer/sender-address')->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.pickup_street_number', '20');
        $this->assertSame('10', $order->fresh()->pickup_street_number);
        $this->deleteJson('/api/v1/customer/sender-address', ['version' => 2])->assertOk();
        $this->getJson('/api/v1/customer/sender-address')->assertOk()->assertJsonPath('data', null);
        $this->assertSame('Via Roma', $order->fresh()->pickup_address);
        $this->assertSame('10', $order->fresh()->pickup_street_number);
        $this->assertDatabaseCount('sender_addresses', 0);
    }

    public function test_preferred_address_is_scoped_to_authenticated_customer_and_never_saves_recipient(): void
    {
        $a = User::factory()->create(['role' => UserRole::Customer]);
        $b = User::factory()->create(['role' => UserRole::Customer]);
        $other = SenderAddress::factory()->create(['customer_id' => $b->id, 'pickup_address' => 'Indirizzo privato B']);
        $this->actingAs($a, 'customer')->getJson('/api/v1/customer/sender-address?customer_id='.$b->slug)->assertOk()->assertJsonPath('data', null);
        $this->putJson('/api/v1/customer/sender-address', [...$this->address(), 'customer_id' => $b->slug])->assertUnprocessable();
        $this->putJson('/api/v1/customer/sender-address', [...$this->address(), 'delivery_address' => 'Non salvare', 'recipient_name' => 'Destinatario'])->assertOk()->assertJsonMissingPath('data.delivery_address')->assertJsonMissingPath('data.recipient_name');
        $this->getJson('/api/v1/customer/sender-address/'.$other->id)->assertNotFound();
        $this->deleteJson('/api/v1/customer/sender-address', ['version' => 1, 'customer_id' => $b->slug])->assertOk();
        $this->assertSame('Indirizzo privato B', $other->fresh()->pickup_address);
        $this->assertSame(1, $b->senderAddresses()->count());
    }

    public function test_validation_conflicts_and_single_address_constraint(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer')->putJson('/api/v1/customer/sender-address', [])->assertUnprocessable()->assertJsonValidationErrors(['pickup_address', 'pickup_street_number', 'pickup_postal_code', 'pickup_city', 'version']);
        $this->putJson('/api/v1/customer/sender-address', [...$this->address(), 'pickup_postal_code' => 'ABC'])->assertUnprocessable();
        $this->putJson('/api/v1/customer/sender-address', $this->address())->assertOk();
        $this->putJson('/api/v1/customer/sender-address', $this->address())->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson('/api/v1/customer/sender-address', [...$this->address(), 'pickup_street_number' => '20'])->assertConflict();
        $this->putJson('/api/v1/customer/sender-address', [...$this->address(), 'version' => 1, 'pickup_street_number' => '20'])->assertOk();
        $this->deleteJson('/api/v1/customer/sender-address', ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('sender_addresses', 1);
    }

    public function test_guests_staff_and_inactive_accounts_cannot_access_customer_address(): void
    {
        $this->getJson('/api/v1/customer/sender-address')->assertUnauthorized();
        foreach ([UserRole::Admin, UserRole::Rider] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'customer')->getJson('/api/v1/customer/sender-address')->assertForbidden();
            $this->putJson('/api/v1/customer/sender-address', $this->address())->assertForbidden();
            $this->deleteJson('/api/v1/customer/sender-address', ['version' => 1])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]), 'customer')->getJson('/api/v1/customer/sender-address')->assertUnauthorized();
    }
}
