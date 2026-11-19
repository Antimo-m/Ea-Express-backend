<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Rules\PlainText;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviousRevisionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_customer_write_endpoint_can_change_the_account_email(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $email = $customer->email;
        $this->actingAs($customer, 'customer');
        $payload = ['email' => 'attacker@example.test', 'name' => $customer->name, 'sender_type' => 'business'];
        $this->patchJson('/api/v1/customer/profile', $payload)->assertUnprocessable();
        $this->patchJson('/api/v1/customer/preferences', [...$payload, 'notify_orders' => true, 'notify_messages' => true])->assertOk();
        $this->patchJson('/api/v1/customer/display-preferences', [...$payload, 'theme' => 'dark', 'notification_sound' => false]);
        $this->putJson('/api/v1/customer/sender-address', [...$payload, 'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '80121', 'pickup_city' => 'Napoli', 'version' => 0]);
        $this->assertSame($email, $customer->fresh()->email);
    }

    public function test_riders_cannot_delete_their_account_and_history_is_kept(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id]);
        $this->actingAs($rider)->delete('/profile', ['password' => 'password'])->assertForbidden();
        $this->actingAs($rider)->deleteJson('/profile')->assertForbidden();
        $this->get('/profile')->assertOk()->assertDontSee('Elimina account');
        $this->assertModelExists($rider);
        $this->assertSame($rider->id, $order->fresh()->rider_id);
    }

    public function test_free_text_fields_reject_control_characters_and_customer_reasons_are_filtered(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id]);
        $this->actingAs($admin)->postJson(route('messages.store', $order), ['body' => "Ciao\u{202E}"])->assertJsonValidationErrors(['body' => PlainText::Message]);
        $this->postJson('/expenses', ['description' => "Carbu\x07rante", 'amount' => '10', 'spent_on' => now()->toDateString()])->assertJsonValidationErrors('description');
        $this->postJson(route('messages.store', $order), ['body' => "Arrivo alle 10.\nGrazie"])->assertJsonMissingValidationErrors('body');

        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $own = Order::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/'.$own->slug.'/cancel', ['reason' => 'Siete degli stronzi', 'version' => 1])->assertJsonValidationErrors('reason');
    }

    public function test_riders_cannot_reach_orders_assigned_to_someone_else_on_any_order_route(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['rider_id' => $owner->id, 'customer_id' => $customer->id, 'status' => \App\OrderStatus::RiderArriving]);
        $before = $order->fresh()->getAttributes();
        $this->actingAs($intruder);
        $requests = [
            ['get', 'orders.show'], ['patch', 'orders.update', ['status' => 'picked_up', 'version' => 1]], ['patch', 'orders.carrier', ['version' => 1]],
            ['patch', 'orders.pickup-schedule', ['version' => 1]], ['patch', 'prices.update', ['version' => 1]], ['patch', 'orders.rider', ['rider_id' => $intruder->slug, 'version' => 1]],
            ['get', 'messages.show'], ['post', 'messages.store', ['body' => 'intrusione']], ['patch', 'messages.read'], ['post', 'messages.share', ['action' => 'renew']],
            ['get', 'notifications.history'], ['post', 'payments.store', ['action' => 'receive', 'version' => 1]],
        ];
        foreach ($requests as $request) {
            [$method, $route, $data] = [...$request, []];
            $status = $this->{$method.'Json'}(route($route, $order), $request[2] ?? [])->status();
            $this->assertContains($status, [403, 404, 422], $route.' ha risposto '.$status);
        }
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('order_messages', 0);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.Order::factory()->create()->slug)->assertNotFound();
    }
}
