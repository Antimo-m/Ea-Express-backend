<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_geographic_endpoints_are_removed_for_staff_and_customers(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => User::factory(), 'customer_id' => User::factory()->create(['role' => UserRole::Customer])->id]);
        $this->actingAs($admin);

        $this->getJson('/tracking/positions')->assertNotFound();
        $this->getJson('/orders/'.$order->slug.'/location')->assertNotFound();
        $this->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();
        $this->deleteJson('/orders/'.$order->slug.'/location/session')->assertNotFound();
        $this->putJson('/orders/'.$order->slug.'/location')->assertNotFound();
        $this->actingAs($order->rider)->getJson('/orders/'.$order->slug.'/location')->assertNotFound();
        $this->actingAs($order->customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug.'/location')->assertNotFound();
    }

    public function test_operational_tracking_remains_and_geolocation_is_disabled(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'tracking_started_at' => now()]);

        $this->actingAs($admin)->get('/orders/'.$order->slug)->assertSee('Il percorso dell’ordine')->assertDontSee('data-rider-gps', false)->assertDontSee('data-tracking-panel', false)->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->get('/dashboard')->assertDontSee('GPS')->assertDontSee('data-tracking-panel', false);
        $this->get('/track/'.$order->tracking_token)->assertOk()->assertDontSee('GPS');
    }
}
