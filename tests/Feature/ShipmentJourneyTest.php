<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tracking_places_the_truck_on_the_registered_stage_without_internal_pricing(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::InTransit, 'tracking_started_at' => now(), 'price_state' => 'awaiting_customer']);
        $position = array_search(OrderStatus::InTransit, OrderStatus::deliveryStages(), true);

        $this->get('/track/'.$order->tracking_token)->assertOk()
            ->assertSee('data-journey-position="'.$position.'"', false)
            ->assertSee('data-journey-state="moving"', false)
            ->assertSee('journey--compact')
            ->assertSee('Cronologia degli aggiornamenti')->assertDontSee('azioni operative')
            ->assertDontSee('la proposta economica');
    }

    public function test_customer_order_detail_exposes_the_journey_without_internal_fields(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::OutForDelivery]);
        $stages = OrderStatus::deliveryStages();

        $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()
            ->assertJsonPath('data.progress.position', array_search(OrderStatus::OutForDelivery, $stages, true))
            ->assertJsonPath('data.progress.state', 'moving')
            ->assertJsonPath('data.progress.exception', null)
            ->assertJsonCount(count($stages), 'data.progress.steps')
            ->assertJsonPath('data.progress.steps.0.state', 'complete')
            ->assertJsonMissingPath('data.progress.pricing');

        $this->assertSame(['status', 'label', 'state', 'at', 'optional', 'note'], array_keys($response->json('data.progress.steps.0')));
    }

    public function test_cancelled_order_stops_the_truck_on_the_last_registered_stage(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Cancelled]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()
            ->assertJsonPath('data.progress.position', 0)
            ->assertJsonPath('data.progress.state', 'stopped')
            ->assertJsonPath('data.progress.exception.status', 'cancelled')
            ->assertJsonPath('data.progress.exception.label', OrderStatus::Cancelled->label());
    }

    public function test_order_list_does_not_compute_the_journey(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders')->assertOk()->assertJsonMissingPath('data.0.progress');
    }
}
