<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_and_customer_can_exchange_escaped_messages_without_exposing_order_details(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($rider)->post(route('messages.share', $order), ['action' => 'renew'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->post(route('messages.store', $order), ['body' => 'Sto arrivando.', 'user_id' => 999])->assertRedirect(route('messages.show', $order));
        $this->assertSame($rider->id, OrderMessage::sole()->user_id);
        $this->get(route('messages.show', $order))->assertOk()->assertSee('Sto arrivando.');
        $this->post('/logout');
        $this->get(route('conversation.show', $order->conversation_token))->assertOk()->assertSee('Sto arrivando.')->assertDontSee($order->recipient_phone)->assertDontSee($order->delivery_address)->assertHeader('Referrer-Policy', 'no-referrer');
        $body = '<script>alert("xss")</script>';
        $this->post(route('conversation.store', $order->conversation_token), ['body' => $body, 'user_id' => $rider->id])->assertSessionHasNoErrors();
        $this->assertNull(OrderMessage::latest('id')->first()->user_id);
        $this->get(route('conversation.show', $order->conversation_token))->assertSee($body)->assertDontSee($body, escape: false);
        $this->assertSame(1, $rider->unreadNotifications()->count());
        $this->actingAs($rider)->patch(route('messages.read', $order))->assertSessionHasNoErrors();
        $this->assertNotNull(OrderMessage::latest('id')->first()->read_at);
        $this->get('/messages')->assertOk()->assertSee($order->reference);
    }

    public function test_customer_link_rotation_revocation_and_expiry_block_both_read_and_write(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::Accepted, 'conversation_token' => Str::random(64), 'conversation_expires_at' => now()->addDay()]);
        $old = $order->conversation_token;
        $this->actingAs($user)->post(route('messages.share', $order), ['action' => 'renew'])->assertSessionHasNoErrors();
        $this->get(route('conversation.show', $old))->assertNotFound();
        $this->post(route('conversation.store', $old), ['body' => 'test'])->assertNotFound();
        $order->refresh();
        $this->travel(31)->days();
        $this->get(route('conversation.show', $order->conversation_token))->assertNotFound();
        $this->post(route('conversation.store', $order->conversation_token), ['body' => 'test'])->assertNotFound();
        $this->post(route('messages.share', $order), ['action' => 'revoke'])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->conversation_token);
        $this->assertDatabaseCount('order_messages', 0);
    }

    public function test_other_rider_cannot_access_send_or_share_an_assigned_conversation(): void
    {
        $order = Order::factory()->create(['rider_id' => User::factory(), 'status' => OrderStatus::Accepted]);
        $this->actingAs(User::factory()->create())->get(route('messages.show', $order))->assertNotFound();
        $this->post(route('messages.store', $order), ['body' => 'intrusione'])->assertForbidden();
        $this->post(route('messages.share', $order), ['action' => 'renew'])->assertForbidden();
        $this->patch(route('messages.read', $order))->assertForbidden();
        $this->assertDatabaseCount('order_messages', 0);
    }

    public function test_notifications_are_private_and_respect_preferences(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $muted = User::factory()->create(['role' => UserRole::Admin, 'notify_messages' => false]);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($rider)->post(route('messages.store', $order), ['body' => 'Aggiornamento'])->assertSessionHasNoErrors();
        $this->assertSame(1, $admin->unreadNotifications()->count());
        $this->assertSame(0, $muted->notifications()->count());
        $notification = $admin->notifications()->sole();
        $this->patch(route('notifications.update', $notification->id))->assertNotFound();
        $this->actingAs($admin)->get('/notifications')->assertOk()->assertSee($order->reference);
        $this->getJson(route('notifications.history', $order->id))->assertOk()->assertJsonFragment(['title' => 'Nuovo messaggio']);
        $this->patch(route('notifications.update', $notification->id))->assertRedirect(route('messages.show', $order));
        $this->assertSame(0, $admin->unreadNotifications()->count());
    }

    public function test_customer_messages_are_validated_and_rate_limited(): void
    {
        $order = Order::factory()->create(['conversation_token' => Str::random(64), 'conversation_expires_at' => now()->addDay()]);
        $url = route('conversation.store', $order->conversation_token);
        $this->post($url, [])->assertSessionHasErrors('body');
        $this->post($url, ['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');
        for ($i = 0; $i < 3; $i++) {
            $this->post($url, ['body' => 'Messaggio'])->assertSessionHasNoErrors();
        }
        $this->post($url, ['body' => 'Troppi messaggi'])->assertTooManyRequests();
        $this->assertDatabaseCount('order_messages', 3);
    }
}
