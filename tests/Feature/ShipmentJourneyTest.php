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

    public function test_journey_shows_full_city_names_below_the_road_only_for_staff(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::InTransit, 'tracking_started_at' => now(), 'pickup_city' => 'San Giuseppe Vesuviano', 'delivery_city' => 'Giugliano in Campania']);

        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()
            ->assertSeeInOrder(['class="journey-road"', 'class="journey-places"', '<span>San Giuseppe Vesuviano</span>', '<span>Giugliano in Campania</span>'], false);

        auth()->logout();
        $this->get('/track/'.$order->tracking_token)->assertOk()
            ->assertDontSee('journey-places')->assertDontSee('Giugliano in Campania');
    }

    public function test_delivered_tracking_celebrates_once_and_shows_how_fresh_the_updates_are(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'tracking_started_at' => now()]);
        $order->events()->create(['status' => OrderStatus::Delivered, 'user_id' => $admin->id]);
        $latest = $order->events()->latest()->value('created_at');

        $this->get('/track/'.$order->tracking_token)->assertOk()
            ->assertSee('data-journey-state="arrived"', false)
            ->assertSee('data-journey-celebrate="'.$order->reference.'"', false)
            ->assertSee('<span class="journey-arrived">Consegnato!</span>', false)
            ->assertSee('class="journey-confetti"', false)
            ->assertSee('data-realtime="connecting"', false)
            ->assertSee('datetime="'.$latest->toIso8601String().'" data-relative-time', false);

        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()
            ->assertSee('<span class="journey-arrived">Consegnato!</span>', false)
            ->assertDontSee('journey-confetti')->assertDontSee('data-journey-celebrate');
    }

    public function test_journey_in_progress_shows_neither_badge_nor_confetti(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery, 'tracking_started_at' => now()]);

        $this->get('/track/'.$order->tracking_token)->assertOk()
            ->assertDontSee('journey-arrived')
            ->assertSee('class="journey-confetti"', false);
    }
}
