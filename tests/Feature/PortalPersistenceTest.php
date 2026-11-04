<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SenderAddress;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function pickup(): array
    {
        return ['pickup_address' => 'Via Roma', 'pickup_street_number' => '10/A', 'pickup_postal_code' => '80100', 'pickup_city' => 'Napoli'];
    }

    private function booking(): array
    {
        return [...$this->pickup(), 'store_name' => 'Cliente', 'parcel_value' => '0', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+390000000000', 'delivery_address' => 'Via Milano', 'delivery_street_number' => '5', 'delivery_postal_code' => '81100', 'delivery_city' => 'Caserta', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'documents', 'urgency' => 'standard', 'package_type' => 'standard'];
    }

    public function test_saved_pickup_contains_only_pickup_fields_and_equivalent_retries_are_idempotent(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer, 'customer')->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'version' => 0, 'recipient_name' => 'Do not save', 'delivery_address' => 'Never saved', 'store_name' => 'Do not save', 'sender_type' => 'private'])
            ->assertOk()->assertExactJson(['data' => [...$this->pickup(), 'version' => 1]]);
        $this->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'pickup_address' => '  VIA   ROMA. ', 'pickup_city' => 'NAPOLI', 'pickup_street_number' => '10 / A', 'version' => 0])
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'pickup_street_number' => '10A', 'version' => 0])->assertConflict();

        $this->assertDatabaseCount('sender_addresses', 1);
        $this->assertDatabaseHas('sender_addresses', ['customer_id' => $customer->id, 'pickup_address' => 'Via Roma', 'version' => 1, 'store_name' => null, 'sender_type' => null]);
    }

    public function test_saved_pickup_is_isolated_and_cross_account_injection_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Customer]);
        $address = SenderAddress::factory()->for($owner, 'customer')->create();
        $other = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($other, 'customer')->getJson('/api/v1/customer/sender-address')->assertExactJson(['data' => null]);
        $this->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'customer_id' => $owner->slug, 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->deleteJson('/api/v1/customer/sender-address', ['version' => 1])->assertNotFound();

        $this->assertModelExists($address);
        $this->assertDatabaseCount('sender_addresses', 1);
    }

    public function test_changed_pickup_and_delete_require_current_version(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $address = SenderAddress::factory()->for($customer, 'customer')->create();

        $this->actingAs($customer, 'customer')->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'pickup_city' => 'Caserta', 'version' => 0])->assertConflict();
        $this->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'pickup_city' => 'Caserta', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->deleteJson('/api/v1/customer/sender-address', ['version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/customer/sender-address', ['version' => 2])->assertOk();

        $this->assertModelMissing($address);
    }

    public function test_account_display_preferences_persist_and_merge_without_affecting_other_accounts(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer, 'customer')->patchJson('/api/v1/customer/display-preferences', ['theme' => 'dark'])->assertOk()->assertJsonPath('data.theme', 'dark');
        $this->patchJson('/api/v1/customer/display-preferences', ['notification_sound' => true, 'role' => 'admin'])->assertOk()->assertJsonPath('data.theme', 'dark')->assertJsonPath('data.notification_sound', true);
        $this->getJson('/api/v1/customer/auth/me')->assertJsonPath('user.portal_preferences.theme', 'dark')->assertJsonPath('user.portal_preferences.notification_sound', true);
        $this->patchJson('/api/v1/customer/display-preferences', ['theme' => 'light', 'notification_sound' => false])->assertOk();

        $this->assertSame(['theme' => 'light', 'notification_sound' => false], $customer->fresh()->portal_preferences);
        $this->assertSame(UserRole::Customer, $customer->fresh()->role);
        $this->assertNull($other->fresh()->portal_preferences);
    }

    public function test_preferences_reject_invalid_values_and_foreign_account_selectors(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer, 'customer')->patchJson('/api/v1/customer/display-preferences', ['theme' => 'neon', 'notification_sound' => 'yes', 'user_id' => $customer->slug])
            ->assertUnprocessable()->assertJsonValidationErrors(['theme', 'notification_sound', 'user_id']);

        $this->assertNull($customer->fresh()->portal_preferences);
    }

    public function test_private_portal_storage_endpoints_require_a_customer_session(): void
    {
        $this->getJson('/api/v1/customer/sender-address')->assertUnauthorized();
        $this->patchJson('/api/v1/customer/display-preferences', ['theme' => 'dark'])->assertUnauthorized();
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $this->actingAs($rider, 'customer')->putJson('/api/v1/customer/sender-address', [...$this->pickup(), 'version' => 0])->assertForbidden();
        $this->patchJson('/api/v1/customer/display-preferences', ['theme' => 'dark'])->assertForbidden();
    }

    public function test_cookie_inventory_is_public_uses_runtime_configuration_and_does_not_invent_consent(): void
    {
        config(['session.cookie' => 'ea-test-session', 'session.lifetime' => 90, 'customer_privacy.reviewed' => true, 'customer_privacy.controller_name' => '', 'customer_privacy.privacy_url' => 'https://example.test/privacy']);

        $this->getJson('/api/v1/customer/privacy')->assertOk()->assertJsonPath('reviewed', false)->assertJsonPath('cookies.0.name', 'ea-test-session')->assertJsonPath('cookies.0.minutes', 90)->assertJsonPath('cookies.0.http_only', true)->assertJsonPath('optional_categories', []);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_sender_type_and_business_identity_come_from_profile_at_review_creation_and_update(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'online_shop', 'business_type' => 'Artigianato']);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($customer, 'customer');
        $data = [...$this->booking(), 'sender_type' => 'private', 'business_type' => 'Injected'];

        $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->assertJsonPath('data.sender_type', 'online_shop')->assertJsonPath('data.business_type', 'Artigianato');
        $created = $this->postJson('/api/v1/customer/orders', $this->checkoutData($data))->assertCreated()->assertJsonPath('data.sender_type', 'online_shop')->assertJsonPath('data.business_type', 'Artigianato');
        $slug = $created->json('data.slug');
        $this->patchJson('/api/v1/customer/orders/'.$slug, $this->checkoutData([...$data, 'version' => 1], $slug))->assertOk()->assertJsonPath('data.sender_type', 'online_shop');

        $this->assertSame('online_shop', Order::sole()->sender_type);
    }

    public function test_invalid_profile_type_stops_checkout_instead_of_assigning_a_default(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'legacy']);

        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/checkout', $this->booking())->assertUnprocessable()->assertJsonPath('errors.sender_type.0', 'Completa la tipologia del tuo profilo prima di prenotare un ritiro.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_profile_change_after_review_requires_a_new_checkout(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private']);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($customer, 'customer');
        $data = $this->checkoutData($this->booking());
        $customer->update(['sender_type' => 'business']);

        $this->postJson('/api/v1/customer/orders', $data)->assertConflict();

        $this->assertDatabaseCount('orders', 0);
    }
}
