<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\ShippingRate;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
        return ['parcel_value' => '50', 'payment_method' => 'cash', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'package_type' => 'standard', 'recipient_name' => 'Mario Rossi', 'recipient_phone' => '+393331234567', 'pickup_address' => 'Via Roma 1', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Milano 2', 'delivery_city' => 'Caserta', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 2, 'category' => 'clothing', 'urgency' => 'standard', 'customer_notes' => 'Citofono Rossi'];
    }

    public function test_customer_auth_is_separate_and_cannot_accept_rider_credentials(): void
    {
        $rider = User::factory()->create();
        $this->postJson($this->api.'/auth/login', ['email' => $rider->email, 'password' => 'password'])->assertUnprocessable();
        $this->getJson($this->api.'/dashboard')->assertUnauthorized();
        $this->postJson($this->api.'/auth/register', ['sender_type' => 'business', 'name' => 'Boutique Centro', 'email' => 'shop@example.com', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123', 'role' => 'admin'])->assertCreated()->assertJsonPath('user.name', 'Boutique Centro')->assertJsonPath('user.notify_orders', true)->assertJsonPath('user.notify_messages', true);
        $user = User::where('email', 'shop@example.com')->sole();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->getJson($this->api.'/auth/me')->assertOk();
        $this->assertGuest('web');
        $this->postJson($this->api.'/auth/logout')->assertOk();
        $this->getJson($this->api.'/auth/me')->assertUnauthorized();
        $this->post('/login', ['email' => $user->email, 'password' => 'SecurePassword123'])->assertSessionHasErrors('email');
    }

    public function test_customer_request_requires_admin_assignment_and_ownership_cannot_be_injected(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $response = $this->actingAs($customer, 'customer')->postJson($this->api.'/orders', $this->checkoutData([...$this->payload(), 'customer_id' => 999, 'rider_id' => $rider->slug, 'status' => 'delivered', 'notes' => 'Injected internal note', 'price_cents' => 1]));
        $response->assertCreated()->assertJsonPath('data.status', 'received');
        $order = Order::sole();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNull($order->rider_id);
        $this->assertNull($order->notes);
        $this->assertSame('Citofono Rossi', $order->customer_notes);
        $this->actingAs($rider, 'web')->get('/orders/incoming')->assertDontSee($order->reference);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/orders/incoming')->assertSee($order->reference);
        $this->patch(route('orders.update', $order), ['status' => 'accepted', 'rider_id' => $rider->slug, 'version' => 1, 'price' => '9.50'])->assertSessionHasNoErrors();
        $this->assertSame(1, $customer->unreadNotifications()->count());
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders/'.$order->slug)->assertOk()->assertJsonPath('data.rider.name', $rider->name)->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.rider.email')->assertJsonMissingPath('data.tracking_token');
    }

    public function test_other_customers_cannot_read_modify_cancel_or_message_an_order(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $order = Order::factory()->create(['customer_id' => $owner->id]);
        $this->actingAs($other, 'customer')->getJson($this->api.'/orders/'.$order->slug)->assertNotFound();
        $this->patchJson($this->api.'/orders/'.$order->slug, [...$this->payload(), 'version' => 1])->assertNotFound();
        $this->postJson($this->api.'/orders/'.$order->slug.'/cancel', ['version' => 1, 'reason' => 'Test'])->assertNotFound();
        $this->postJson($this->api.'/orders/'.$order->slug.'/messages', ['body' => 'Test'])->assertNotFound();
        $this->getJson($this->api.'/orders')->assertJsonPath('meta.total', 0);
    }

    public function test_pending_requests_can_be_edited_and_cancelled_but_conflicts_are_rejected(): void
    {
        $user = $this->customer();
        $order = Order::factory()->create(['customer_id' => $user->id]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($user, 'customer')->patchJson($this->api.'/orders/'.$order->slug, $this->checkoutData([...$this->payload(), 'version' => 1], $order->slug))->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($this->api.'/orders/'.$order->slug, [...$this->payload(), 'version' => 1, 'checkout_token' => 'stale-review'])->assertConflict();
        $this->postJson($this->api.'/orders/'.$order->slug.'/cancel', ['version' => 2, 'reason' => 'Richiesta duplicata'])->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->patchJson($this->api.'/orders/'.$order->slug, [...$this->payload(), 'version' => 3, 'checkout_token' => 'stale-review'])->assertConflict();
    }

    public function test_conversation_is_shared_with_rider_and_reads_are_separate_by_sender(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($customer, 'customer')->postJson($this->api.'/orders/'.$order->slug.'/messages', ['body' => 'Il pacco è pronto'])->assertCreated();
        $this->actingAs($rider, 'web')->get(route('messages.show', $order))->assertSee('Il pacco è pronto');
        $this->post(route('messages.store', $order), ['body' => 'Arrivo alle 10'])->assertSessionHasNoErrors();
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders/'.$order->slug.'/messages')->assertOk()->assertJsonPath('data.0.sender', 'team');
        $this->patchJson($this->api.'/orders/'.$order->slug.'/messages/read')->assertOk();
        $this->assertNotNull($order->messages()->whereNotNull('user_id')->sole()->read_at);
        $this->assertNull($order->messages()->whereNull('user_id')->sole()->read_at);
        $this->getJson($this->api.'/notifications')->assertJsonPath('meta.unread', 1);
        $this->patchJson($this->api.'/notifications/read-all')->assertOk();
    }

    public function test_dashboard_profile_and_preferences_only_use_owned_data(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $hiddenRider = User::factory()->create();
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        Order::factory()->create(['customer_id' => $this->customer()->id, 'rider_id' => $hiddenRider->id]);
        $this->actingAs($customer, 'customer')->getJson($this->api.'/dashboard')->assertOk()->assertJsonPath('metrics.active', 1);
        $this->getJson($this->api.'/couriers')->assertNotFound();
        $this->getJson($this->api.'/corrieri')->assertNotFound();
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

    public function test_conversation_filters_keep_orders_scoped_and_reject_legacy_courier_filter(): void
    {
        $customer = $this->customer();
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id]);
        OrderMessage::factory()->create(['order_id' => $order->id, 'user_id' => $rider->id]);
        OrderMessage::factory()->create(['order_id' => $order->id, 'user_id' => null]);
        Order::factory()->create(['customer_id' => $customer->id]);
        $hidden = Order::factory()->create(['customer_id' => $this->customer()->id, 'rider_id' => $rider->id]);
        OrderMessage::factory()->create(['order_id' => $hidden->id, 'user_id' => $rider->id]);
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders?has_messages=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', $order->slug)
            ->assertJsonPath('data.0.messages_count', 2)->assertJsonPath('data.0.unread_messages_count', 1);
        $this->patchJson($this->api.'/orders/'.$order->slug.'/messages/read')->assertOk();
        $this->getJson($this->api.'/orders?has_messages=1')->assertJsonPath('data.0.unread_messages_count', 0);
        $this->getJson($this->api.'/orders?courier='.$rider->id)->assertUnprocessable()->assertJsonValidationErrors('courier');
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
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', $pending->slug);
    }

    public function test_complete_customer_admin_and_verified_rider_delivery_flow(): void
    {
        Notification::fake([RiderEmailOtp::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = $this->customer();
        $otherRider = User::factory()->create();
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($admin, 'web')->post('/settings/users', ['name' => 'Rider del flusso', 'email' => 'rider-flow@example.test', 'password' => 'PasswordSicura123', 'password_confirmation' => 'PasswordSicura123'])->assertSessionHasNoErrors();
        $rider = User::where('email', 'rider-flow@example.test')->sole();
        $this->assertNull($rider->email_verified_at);
        $this->actingAs($customer, 'customer')->postJson($this->api.'/orders', $this->checkoutData($this->payload()))->assertCreated();
        $order = Order::sole();
        $this->getJson($this->api.'/orders/'.$order->slug)->assertOk();
        $this->actingAs($otherRider, 'web')->get('/orders/incoming')->assertDontSee($order->reference);
        $this->actingAs($admin, 'web')->patch(route('orders.update', $order), ['status' => 'accepted', 'rider_id' => $rider->slug, 'version' => 1])->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => $rider->email, 'password' => 'PasswordSicura123'])->assertRedirect(route('email-otp.notice'));
        $this->get('/orders/in-progress')->assertRedirect(route('email-otp.notice'));
        Notification::assertSentTo($rider, RiderEmailOtp::class);
        $code = Notification::sent($rider, RiderEmailOtp::class)->sole()->code;
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertNotNull($rider->fresh()->email_verified_at);
        $this->actingAs($rider->fresh(), 'web')->get('/orders/in-progress')->assertOk()->assertSee($order->reference);
        foreach (['rider_arriving', 'picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->patch(route('orders.update', $order), ['status' => $status, 'version' => $order->fresh()->version])->assertSessionHasNoErrors();
        }
        $this->postJson(route('messages.store', $order), ['body' => 'Consegna completata'])->assertCreated();
        $this->get('/tracking')->assertNotFound();
        $this->actingAs($otherRider)->get(route('orders.show', $order))->assertNotFound();
        $this->getJson(route('messages.show', $order))->assertNotFound();
        $this->actingAs($customer, 'customer')->getJson($this->api.'/orders/'.$order->slug)->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.rider.slug', $rider->slug);
        $this->actingAs($admin, 'web')->get('/balance')->assertOk();
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }
}
