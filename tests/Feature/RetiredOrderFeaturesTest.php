<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\DeliveryDeadline;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetiredOrderFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_reporting_endpoints_cannot_change_orders_or_their_history(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery]);

        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patchJson('/orders/'.$order->slug.'/delay', ['version' => 1, 'reason' => 'Operazione non più disponibile'])->assertNotFound();

        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->version);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_archived_reports_do_not_affect_customer_status_or_attention_and_are_preserved(): void
    {
        $this->freezeTime();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'rider_id' => $rider->id,
            'status' => OrderStatus::OutForDelivery, 'urgency' => 'urgent',
            'estimated_at' => now()->addHours(2), 'tracking_started_at' => now(),
        ]);
        $order->forceFill(['delay_reported_at' => now(), 'delay_reported_by' => $rider->id])->save();
        $order->events()->create(['user_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'public_note' => 'Il pacco è in consegna.']);
        $archived = $order->events()->make(['user_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'public_note' => 'Segnalazione storica da conservare.']);
        $archived->forceFill(['delay_action' => 'reported'])->save();

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/dashboard')
            ->assertOk()->assertJsonPath('metrics.attention', 0)->assertJsonPath('metrics.active', 1);
        $this->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()
            ->assertJsonMissingPath('data.delay')->assertJsonMissingPath('data.delay_reported_at')
            ->assertJsonPath('data.urgency', 'urgent')->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.message', 'Il pacco è in consegna.')
            ->assertJsonPath('data.estimated_at', $order->estimated_at->toIso8601String());
        $this->get('/track/'.$order->tracking_token)->assertOk()->assertSee('Previsione:')
            ->assertSee('Il pacco è in consegna.')->assertDontSee('Segnalazione storica da conservare.');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get('/orders/'.$order->slug)->assertOk()->assertDontSee('Segnala ritardo')->assertDontSee('order-delay-panel');

        $this->assertSame($order->estimated_at->toISOString(), app(DeliveryDeadline::class)->forOrder($order)['at']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'delay_reported_by' => $rider->id, 'urgency' => 'urgent']);
        $this->assertDatabaseHas('order_events', ['id' => $archived->id, 'delay_action' => 'reported']);
        $this->assertDatabaseCount('order_events', 2);
    }

    public function test_verified_email_rider_access_does_not_require_phone_verification(): void
    {
        $rider = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        $this->actingAs($rider)->get('/dashboard')->assertOk();
        $this->get('/orders/in-progress')->assertOk();
        $this->get('/verify-phone-otp')->assertNotFound();
        $this->post('/verify-phone-otp/send')->assertNotFound();
        $this->post('/verify-phone-otp/confirm', ['code' => '123456'])->assertNotFound();
    }
}
