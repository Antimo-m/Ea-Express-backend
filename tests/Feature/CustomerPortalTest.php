<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private string $api = '/api/v1/customer';

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer]);
    }

    private function payload(): array
    {
        return ['recipient_name' => 'Mario Rossi', 'recipient_phone' => '+393331234567', 'pickup_address' => 'Via Roma 1', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Milano 2', 'delivery_city' => 'Caserta', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 2, 'category' => 'clothing', 'urgency' => 'standard', 'customer_notes' => 'Citofono Rossi'];
    }

    public function test_customer_auth_is_separate_and_cannot_accept_rider_credentials(): void
    {
        $rider = User::factory()->create();
        $this->postJson($this->api.'/auth/login', ['email' => $rider->email, 'password' => 'password'])->assertUnprocessable();
        $this->getJson($this->api.'/dashboard')->assertUnauthorized();
        $this->postJson($this->api.'/auth/register', ['name' => 'Boutique Centro', 'email' => 'shop@example.com', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123', 'role' => 'admin'])->assertCreated()->assertJsonPath('user.name', 'Boutique Centro')->assertJsonPath('user.notify_orders', true)->assertJsonPath('user.notify_messages', true);
        $user = User::where('email', 'shop@example.com')->sole();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->getJson($this->api.'/auth/me')->assertOk();
        $this->assertGuest('web');
        $this->postJson($this->api.'/auth/logout')->assertOk();
        $this->getJson($this->api.'/auth/me')->assertUnauthorized();
        $this->post('/login', ['email' => $user->email, 'password' => 'SecurePassword123'])->assertSessionHasErrors('email');
    }

    public function test_customer_request_appears_for_rider_and_ownership_cannot_be_injected(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $response = $this->actingAs($customer, 'customer')->postJson($this->api.'/orders', [...$this->payload(), 'customer_id' => 999, 'rider_id' => $rider->id, 'status' => 'delivered', 'notes' => 'Injected internal note', 'price_cents' => 1]);
        $response->assertCreated()->assertJsonPath('data.status', 'received');
        $order = Order::sole();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNull($order->rider_id);
        $this->assertNull($order->notes);
        $this->assertSame('Citofono Rossi', $order->customer_notes);
        $this->actingAs($rider, 'web')->get('/orders/incoming')->assertSee($order->reference);
        $this->patch(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '9.50'])->assertSessionHasNoErrors();
        $this->assertSame(1, $customer->unreadNotifications()->count());
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders/'.$order->id)->assertOk()->assertJsonPath('data.courier.name', $rider->name)->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.courier.email')->assertJsonMissingPath('data.tracking_token');
    }

    public function test_other_customers_cannot_read_modify_cancel_or_message_an_order(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $order = Order::factory()->create(['customer_id' => $owner->id]);
        $this->actingAs($other, 'customer')->getJson($this->api.'/orders/'.$order->id)->assertNotFound();
        $this->patchJson($this->api.'/orders/'.$order->id, [...$this->payload(), 'version' => 1])->assertNotFound();
        $this->postJson($this->api.'/orders/'.$order->id.'/cancel', ['version' => 1, 'reason' => 'Test'])->assertNotFound();
        $this->postJson($this->api.'/orders/'.$order->id.'/messages', ['body' => 'Test'])->assertNotFound();
        $this->getJson($this->api.'/orders')->assertJsonPath('meta.total', 0);
    }

    public function test_pending_requests_can_be_edited_and_cancelled_but_conflicts_are_rejected(): void
    {
        $user = $this->customer();
        $order = Order::factory()->create(['customer_id' => $user->id]);
        $this->actingAs($user, 'customer')->patchJson($this->api.'/orders/'.$order->id, [...$this->payload(), 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($this->api.'/orders/'.$order->id, [...$this->payload(), 'version' => 1])->assertConflict();
        $this->postJson($this->api.'/orders/'.$order->id.'/cancel', ['version' => 2, 'reason' => 'Richiesta duplicata'])->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->patchJson($this->api.'/orders/'.$order->id, [...$this->payload(), 'version' => 3])->assertConflict();
    }

    public function test_conversation_is_shared_with_rider_and_reads_are_separate_by_sender(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($customer, 'customer')->postJson($this->api.'/orders/'.$order->id.'/messages', ['body' => 'Il pacco è pronto'])->assertCreated();
        $this->actingAs($rider, 'web')->get(route('messages.show', $order))->assertSee('Il pacco è pronto');
        $this->post(route('messages.store', $order), ['body' => 'Arrivo alle 10'])->assertSessionHasNoErrors();
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders/'.$order->id.'/messages')->assertOk()->assertJsonPath('data.0.sender', 'courier');
        $this->patchJson($this->api.'/orders/'.$order->id.'/messages/read')->assertOk();
        $this->assertNotNull($order->messages()->whereNotNull('user_id')->sole()->read_at);
        $this->assertNull($order->messages()->whereNull('user_id')->sole()->read_at);
        $this->getJson($this->api.'/notifications')->assertJsonPath('meta.unread', 1);
        $this->patchJson($this->api.'/notifications/read-all')->assertOk();
    }

    public function test_dashboard_couriers_profile_and_preferences_only_use_owned_data(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $hiddenRider = User::factory()->create();
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        Order::factory()->create(['customer_id' => $this->customer()->id, 'rider_id' => $hiddenRider->id]);
        $this->actingAs($customer, 'customer')->getJson($this->api.'/dashboard')->assertOk()->assertJsonPath('metrics.active', 1);
        $this->getJson($this->api.'/couriers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rider->id)->assertJsonMissingPath('data.0.email');
        $this->patchJson($this->api.'/profile', ['name' => 'Negozio nuovo', 'email' => $customer->email, 'role' => 'admin'])->assertOk();
        $this->patchJson($this->api.'/preferences', ['notify_orders' => false, 'notify_messages' => true])->assertOk();
        $this->assertSame(UserRole::Customer, $customer->fresh()->role);
        $this->assertFalse($customer->fresh()->notify_orders);
    }

    public function test_customer_endpoints_enforce_csrf_and_reject_disabled_accounts(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer, 'customer');
        $this->app['env'] = 'local';
        $this->postJson($this->api.'/orders', $this->payload())->assertStatus(419);
        $customer->is_active = false;
        $customer->save();
        $this->getJson($this->api.'/auth/me')->assertUnauthorized();
    }

    public function test_courier_and_conversation_filters_keep_messages_and_orders_scoped_to_customer(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id]);
        OrderMessage::factory()->create(['order_id' => $order->id, 'user_id' => $rider->id]);
        OrderMessage::factory()->create(['order_id' => $order->id, 'user_id' => null]);
        Order::factory()->create(['customer_id' => $customer->id]);
        $hidden = Order::factory()->create(['customer_id' => $this->customer()->id, 'rider_id' => $rider->id]);
        OrderMessage::factory()->create(['order_id' => $hidden->id, 'user_id' => $rider->id]);
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders?courier='.$rider->id.'&has_messages=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.messages_count', 2)->assertJsonPath('data.0.unread_messages_count', 1);
        $this->patchJson($this->api.'/orders/'.$order->id.'/messages/read')->assertOk();
        $this->getJson($this->api.'/orders?has_messages=1')->assertJsonPath('data.0.unread_messages_count', 0);
        $this->getJson($this->api.'/orders?courier=invalid')->assertUnprocessable();
    }

    public function test_completed_orders_do_not_appear_among_pending_pickups(): void
    {
        $customer = $this->customer();
        $pending = Order::factory()->create(['customer_id' => $customer->id]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered]);
        $pickedUp = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::InTransit]);
        $pickedUp->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $pickedUp->created_by]);
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders?kind=pickup')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pending->id);
    }
}
