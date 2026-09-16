<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['recipient_name' => 'Mario Rossi', 'recipient_phone' => '+393331234567', 'pickup_address' => 'Via Roma 1', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Milano 2', 'delivery_city' => 'Caserta', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 2, 'category' => 'other', 'urgency' => 'standard'];
    }

    public function test_customer_can_save_and_edit_content_description_and_rider_can_read_it(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $response = $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', [...$this->payload(), 'category' => 'electronics', 'content_description' => 'Tastiera e mouse'])
            ->assertCreated()->assertJsonPath('data.category_label', 'Elettronica e accessori: Tastiera e mouse');
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame('Tastiera e mouse', $order->content_description);
        $this->patchJson('/api/v1/customer/orders/'.$order->id, [...$this->payload(), 'category' => 'other', 'content_description' => 'Ceramiche artigianali', 'version' => 1])
            ->assertOk()->assertJsonPath('data.content_description', 'Ceramiche artigianali');
        $this->assertSame('Ceramiche artigianali', $order->fresh()->content_description);
        $this->actingAs(User::factory()->create(), 'web')->get('/orders/'.$order->id)->assertOk()->assertSee('Altro: Ceramiche artigianali');
    }

    public function test_content_validation_rejects_unknown_categories_and_overlong_descriptions(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', [...$this->payload(), 'category' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson('/api/v1/customer/orders', [...$this->payload(), 'content_description' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('content_description');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_private_accounts_do_not_keep_business_fields_and_can_later_describe_a_business(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Rita Rossi', 'email' => 'rita@example.test', 'password' => 'PasswordSicura123', 'password_confirmation' => 'PasswordSicura123', 'sender_type' => 'private', 'business_type' => 'Negozio', 'business_description' => 'Da eliminare'])
            ->assertCreated()->assertJsonPath('user.sender_type', 'private')->assertJsonPath('user.business_type', null);
        $user = User::where('email', 'rita@example.test')->sole();
        $this->assertNull($user->business_description);
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Rita Lab', 'email' => $user->email, 'sender_type' => 'business', 'business_type' => 'Restauro di strumenti musicali', 'business_description' => 'Riparazioni artigianali su misura'])
            ->assertOk();
        $this->assertSame('Restauro di strumenti musicali', $user->fresh()->business_type);
        $this->getJson('/api/v1/customer/auth/me')->assertJsonPath('user.business_description', 'Riparazioni artigianali su misura');
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Rita Lab', 'email' => $user->email, 'sender_type' => 'admin'])->assertUnprocessable();
    }

    public function test_customer_declares_parcel_value_without_setting_shipping_cost_or_changing_identity_history(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'business_type' => 'Fiorista']);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', [...$this->payload(), 'store_name' => 'Rita Rossi', 'sender_type' => 'private', 'business_type' => 'Non pertinente', 'parcel_value' => '123,45', 'price_cents' => 1, 'parcel_value_cents' => 99])
            ->assertCreated()->assertJsonPath('data.parcel_value_cents', 12345)->assertJsonPath('data.price_cents', null)->assertJsonPath('data.business_type', null)->assertJsonPath('data.store_name', 'Rita Rossi');
        $order = Order::sole();
        $this->assertSame(12345, $order->parcel_value_cents);
        $this->assertNull($order->price_cents);
        $this->patchJson('/api/v1/customer/orders/'.$order->id, [...$this->payload(), 'version' => 1, 'parcel_value' => '200.10'])->assertOk()->assertJsonPath('data.parcel_value_cents', 20010);
        $customer->update(['name' => 'Nome aggiornato']);
        $this->assertSame('Rita Rossi', $order->fresh()->store_name);
        $this->postJson('/api/v1/customer/orders', [...$this->payload(), 'parcel_value' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('parcel_value');
    }

    public function test_notifications_are_paginated_by_parcel_with_scoped_expandable_history_and_feed(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $second = Order::factory()->create(['customer_id' => $customer->id]);
        for ($index = 0; $index < 25; $index++) {
            $customer->notify(new OrderActivity($order->id, $order->reference, 'Aggiornamento '.$index));
        }
        $customer->notify(new OrderActivity($second->id, $second->reference, 'Altro pacco'));
        $other->notify(new OrderActivity($order->id, $order->reference, 'Evento riservato'));
        $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/notifications?grouped=1')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread', 26);
        $group = collect($response->json('data'))->firstWhere('order_id', $order->id);
        $this->assertSame(25, $group['total']);
        $this->assertSame(25, $group['unread']);
        $this->getJson('/api/v1/customer/notifications/orders/'.$order->id)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2)->assertJsonMissing(['title' => 'Evento riservato']);
        $this->getJson('/api/v1/customer/notifications/orders/'.$order->id.'?page=2')->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/customer/notifications/feed')->assertOk()->assertJsonCount(26, 'items')->assertJsonPath('unread', 26);
        $this->patchJson('/api/v1/customer/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/customer/notifications?grouped=1')->assertJsonPath('meta.unread', 0)->assertJsonPath('data.0.unread', 0);
        $this->getJson('/notifications/feed')->assertForbidden();
    }

    public function test_rider_notification_groups_and_workflow_remain_authorized(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create();
        $rider->notify(new OrderActivity($order->id, $order->reference, 'Pacco pronto'));
        $this->actingAs($rider)->get('/notifications')->assertOk()->assertSee('data-notification-group', false)->assertSee($order->reference);
        $this->getJson('/notifications/feed')->assertOk()->assertJsonPath('unread', 1);
        $this->getJson('/notifications/orders/'.$order->id)->assertJsonCount(1, 'data');
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Prendi in carico')->assertSee('Altre azioni e imprevisti');
        $this->patch('/orders/'.$order->id, ['version' => 1, 'status' => 'accepted', 'price' => '8.50'])->assertSessionHasNoErrors();
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Parti per il ritiro');
        $this->assertSame(850, $order->fresh()->price_cents);
    }
}
