<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RecipientIncident;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\RecipientIdentity;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tracking_returns_staff_to_the_authorized_order_and_guests_to_entry(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['tracking_started_at' => now(), 'rider_id' => $admin->id]);
        config(['customer.frontend_url' => 'https://portal.example.test']);

        $this->get(route('tracking.public', $order->tracking_token))->assertViewHas('returnUrl', url('/'));
        $this->actingAs($admin)->get(route('tracking.public', $order->tracking_token))
            ->assertViewHas('returnUrl', route('orders.show', $order))->assertDontSee('portal.example.test');
        $otherRider = User::factory()->create();
        $this->actingAs($otherRider)->get(route('tracking.public', $order->tracking_token))
            ->assertViewHas('returnUrl', route('dashboard'));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer)->get(route('tracking.public', $order->tracking_token))
            ->assertViewHas('returnUrl', 'https://portal.example.test/shipments');
    }

    public function test_order_tracking_is_visible_and_recipient_uses_a_static_badge(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'rider_id' => $admin->id]);
        app(RecipientRisk::class)->record($order, $admin);
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Received, 'note' => 'Richiesta iniziale']);
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Accepted, 'note' => 'Rider assegnato: '.$admin->name]);

        $response = $this->actingAs($admin)->get(route('orders.show', $order));

        $response->assertSee('id="order-tracking"', false)->assertSeeInOrder(['Richiesta iniziale', 'Rider assegnato:'])
            ->assertDontSee('Tracking del cliente')->assertDontSee('Apri tracking cliente')
            ->assertSee('is-unreliable')->assertSee('recipient-status-danger');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//*[@id="order-tracking"]/ancestor::details')->length);
        $this->assertSame(1, $xpath->query('//*[@data-tracking-panel]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(@class,"recipient-risk")]//summary')->length);
        $this->get(route('orders.history'))->assertSee('is-unreliable')->assertSee('NON AFFIDABILE');
        Order::factory()->create([...$order->only(RecipientIdentity::Fields), 'status' => OrderStatus::Accepted, 'rider_id' => $admin->id, 'pickup_date' => now('Europe/Rome')->toDateString()]);
        $groups = $this->get(route('pickups.index'))->viewData('groups');
        $this->get(route('pickups.index', ['group' => $groups->keys()->first()]))->assertSee('is-unreliable')->assertSee('NON AFFIDABILE');
    }

    public function test_recipient_register_search_filters_actual_incidents_and_preserves_removed_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['recipient_name' => 'Pierluigi Di Stefano', 'recipient_phone' => '+393331234567']);
        app(RecipientRisk::class)->record($order, $admin);
        $incident = RecipientIncident::sole();

        $this->actingAs($admin)->get('/recipient-incidents?q=Pierluigi')
            ->assertViewHas('activeProfiles', 1)->assertSee('Pierluigi Di Stefano');
        $this->get('/recipient-incidents?q=NonEsiste')->assertViewHas('profiles', fn ($profiles): bool => $profiles->total() === 0);
        $this->patch(route('recipient-incidents.update', $incident), ['action' => 'dismiss', 'version' => $incident->version, 'correction_reason' => 'Segnalazione non confermata'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/recipient-incidents')->assertViewHas('activeProfiles', 0)->assertViewHas('profiles', fn ($profiles): bool => $profiles->total() === 0);
        $this->get('/recipient-incidents?state=restored&q=Pierluigi')->assertSee('Pierluigi Di Stefano')->assertSee('Segnalazione rimossa');
        $this->actingAs(User::factory()->create())->get('/recipient-incidents')->assertForbidden();
    }

    public function test_rate_editor_omits_measurement_fields_without_erasing_existing_external_limits(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rate = ShippingRate::factory()->create(['shipping_type' => 'external', 'city' => 'Milano', 'city_key' => 'milano', 'postal_code' => '20121', 'postal_codes' => ['20121'], 'max_weight_kg' => 10, 'max_dimension_cm' => 60, 'delivery_days_min' => 2, 'delivery_days_max' => 4]);

        $this->actingAs($admin)->get(route('rates.index'))->assertDontSee('name="max_weight_kg"', false)->assertDontSee('name="max_dimension_cm"', false);
        $response = $this->postJson(route('rates.store', $rate), ['city' => 'Milano', 'postal_code' => '20121', 'price' => '16.50', 'active' => true, 'delivery_time' => 'Da due a quattro giorni']);

        $response->assertCreated();
        $revised = ShippingRate::findOrFail($response->json('data.id'));
        $this->assertSame($rate->max_weight_kg, $revised->max_weight_kg);
        $this->assertSame($rate->max_dimension_cm, $revised->max_dimension_cm);
        $this->assertSame(2, $revised->delivery_days_min);
        $this->assertSame(4, $revised->delivery_days_max);
        $this->assertSame(1650, $revised->price_cents);
    }
}
