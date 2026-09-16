<?php

namespace Tests\Feature;

use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_channel_authorization_is_scoped_to_the_correct_account_and_portal(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-id']);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $this->getJson('/api/v1/customer/realtime/configuration')->assertUnauthorized();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-customer.'.$customer->id])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/v1/customer/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-customer.'.$rider->id])->assertForbidden();
        $this->postJson('/api/v1/customer/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-staff.'.$rider->id])->assertForbidden();
        $this->actingAs($rider, 'web')->postJson('/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-staff.'.$rider->id])->assertOk();
        $this->postJson('/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-customer.'.$customer->id])->assertForbidden();
    }

    public function test_realtime_configuration_exposes_only_public_connection_settings_and_disabled_accounts_cannot_subscribe(): void
    {
        config(['broadcasting.connections.reverb.key' => 'public-key', 'broadcasting.connections.reverb.secret' => 'private-secret']);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/realtime/configuration')
            ->assertOk()->assertExactJson(['key' => 'public-key', 'host' => config('realtime.host'), 'port' => config('realtime.port'), 'scheme' => config('realtime.scheme'), 'channel' => 'customer.'.$customer->id]);
        $customer->is_active = false;
        $customer->save();
        $this->postJson('/api/v1/customer/realtime/auth', ['socket_id' => '12.34', 'channel_name' => 'private-customer.'.$customer->id])->assertUnauthorized();
    }

    public function test_tracking_subscription_requires_the_matching_token_of_an_active_tracking_order(): void
    {
        config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-id']);
        $order = Order::factory()->create(['tracking_started_at' => now()]);
        $path = '/track/'.$order->tracking_token.'/realtime';
        $channel = 'private-tracking.'.hash('sha256', $order->tracking_token);
        $this->postJson($path.'/auth', ['socket_id' => '12.34', 'channel_name' => $channel])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson($path.'/auth', ['socket_id' => '12.34', 'channel_name' => 'private-customer.1'])->assertForbidden();
        $this->getJson('/track/'.str_repeat('a', 64).'/realtime/configuration')->assertNotFound();
        $order->tracking_started_at = null;
        $order->save();
        $this->postJson($path.'/auth', ['socket_id' => '12.34', 'channel_name' => $channel])->assertNotFound();
    }

    public function test_events_only_reach_current_participants_and_do_not_expose_order_details(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $event = new WorkspaceUpdated($order->id, 'messages');
        $names = collect($event->broadcastOn())->pluck('name')->all();
        $this->assertContains('private-customer.'.$customer->id, $names);
        $this->assertContains('private-staff.'.$rider->id, $names);
        $this->assertNotContains('private-customer.'.$other->id, $names);
        $this->assertSame(['event_id', 'order_id', 'kind'], array_keys($event->broadcastWith()));
        $rider->is_active = false;
        $rider->save();
        $this->assertNotContains('private-staff.'.$rider->id, collect($event->broadcastOn())->pluck('name')->all());
    }

    public function test_receipts_only_acknowledge_incoming_selected_messages_and_are_idempotent(): void
    {
        Event::fake([WorkspaceUpdated::class]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id]);
        $incoming = $order->messages()->make(['body' => 'Messaggio']);
        $incoming->user_id = $rider->id;
        $incoming->save();
        $own = $order->messages()->create(['body' => 'Risposta']);
        $path = '/api/v1/customer/orders/'.$order->id.'/messages/read';
        $this->actingAs($customer, 'customer')->patchJson($path, ['ids' => [$incoming->id, $own->id], 'state' => 'delivered'])->assertOk();
        $this->assertNotNull($incoming->fresh()->delivered_at);
        $this->assertNull($incoming->fresh()->read_at);
        $this->assertNull($own->fresh()->delivered_at);
        $this->patchJson($path, ['ids' => [$incoming->id], 'state' => 'read'])->assertOk();
        $readAt = $incoming->fresh()->read_at;
        $this->travel(2)->minutes();
        $this->patchJson($path, ['ids' => [$incoming->id], 'state' => 'read'])->assertOk();
        $this->assertTrue($readAt->equalTo($incoming->fresh()->read_at));
        Event::assertDispatchedTimes(WorkspaceUpdated::class, 2);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->patchJson($path, ['ids' => [$incoming->id], 'state' => 'read'])->assertNotFound();
    }
}
