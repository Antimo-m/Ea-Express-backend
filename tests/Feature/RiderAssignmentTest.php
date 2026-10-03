<?php

namespace Tests\Feature;

use App\Actions\NotifyOrderParticipants;
use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_select_self_or_an_active_rider_and_assignment_is_audited(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $disabled = User::factory()->create(['is_active' => false]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($admin);
        foreach ([null, $otherAdmin->id, $customer->id, $disabled->id, 999999] as $id) {
            $this->patchJson(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '12.50', 'rider_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('rider_id');
            $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        }
        $this->get(route('orders.show', $order))->assertSee('Rider da assegnare')->assertSee($rider->name)->assertDontSee($disabled->name);
        if ($directory = getenv('EA_RIDER_UI_CAPTURE')) {
            file_put_contents($directory.'/admin-order.html', $this->get(route('orders.show', $order))->getContent());
        }
        $this->patch(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '12.50', 'rider_id' => $rider->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($rider->id, $order->fresh()->rider_id);
        $this->assertSame($admin->id, $order->fresh()->assigned_by);
        $this->assertNotNull($order->fresh()->assigned_at);
        $this->assertDatabaseHas('economic_audits', ['entity_id' => $order->id, 'action' => 'rider.assigned', 'user_id' => $admin->id]);
        $this->patchJson(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '9', 'rider_id' => $rider->id])->assertUnprocessable();
    }

    public function test_rider_is_denied_every_administrative_endpoint_and_order_creation(): void
    {
        $rider = User::factory()->create();
        $this->actingAs($rider);
        foreach (['/balance', '/pending', '/reports', '/stores', '/economic-audits', '/movements', '/settings', '/settings/users', '/settings/users/create', '/rates', '/rates/quote', '/orders/create'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        foreach (['/orders', '/orders/checkout/edit', '/rates', '/expenses', '/movements', '/pending', '/settings/users'] as $path) {
            $this->postJson($path, [])->assertForbidden();
        }
        $this->get('/dashboard')->assertOk()->assertDontSee('Importi in corso')->assertDontSee('Amministrazione e contabilità')->assertDontSee('Nuova richiesta');
        if ($directory = getenv('EA_RIDER_UI_CAPTURE')) {
            file_put_contents($directory.'/rider-dashboard.html', $this->get('/dashboard')->getContent());
        }
        $this->patch('/profile', ['name' => $rider->name, 'email' => $rider->email, 'role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Rider, $rider->fresh()->role);
    }

    public function test_lists_details_tracking_messages_and_labels_are_isolated(): void
    {
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $incoming = Order::factory()->create(['created_by' => $rider->id]);
        $mine = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $theirs = Order::factory()->create(['rider_id' => $other->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($rider);
        foreach ([$incoming, $theirs] as $hidden) {
            $this->get(route('orders.show', $hidden))->assertNotFound();
            $this->get(route('messages.show', $hidden))->assertNotFound();
            $this->get(route('labels.index', ['ids' => [$hidden->id]]))->assertNotFound();
            $this->patchJson(route('orders.update', $hidden), ['status' => 'rider_arriving', 'version' => 1])->assertForbidden();
            $this->postJson(route('messages.store', $hidden), ['body' => 'Intrusione'])->assertForbidden();
        }
        foreach (['/orders/incoming', '/orders/in-progress', '/pickups', '/tracking', '/dashboard'] as $path) {
            $this->get($path)->assertOk()->assertDontSee($incoming->reference)->assertDontSee($theirs->reference);
        }
        $this->get(route('orders.show', $mine))->assertOk()->assertSee($mine->reference);
        $this->patch(route('orders.update', $mine), ['status' => 'rider_arriving', 'version' => 1])->assertSessionHasNoErrors();
        $this->postJson(route('messages.store', $mine), ['body' => 'Sto arrivando'])->assertCreated();
        $this->assertDatabaseHas('order_messages', ['order_id' => $mine->id, 'user_id' => $rider->id, 'body' => 'Sto arrivando']);
        $this->assertNotNull($mine->fresh()->tracking_started_at);
    }

    public function test_reassignment_removes_old_rider_access_including_notifications(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $first->id, 'status' => OrderStatus::Accepted]);
        app(NotifyOrderParticipants::class)->handle($order, 'Ordine assegnato', $admin->id);
        $this->actingAs($admin)->patch(route('orders.rider', $order), ['rider_id' => $second->id, 'version' => 1])->assertSessionHasNoErrors();
        $this->actingAs($first)->get(route('orders.show', $order))->assertNotFound();
        $this->get('/notifications')->assertDontSee($order->reference);
        $this->getJson('/notifications/orders/'.$order->id)->assertJsonCount(0, 'data');
        $this->actingAs($second)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($admin)->patch(route('users.update', $second), ['action' => 'deactivate'])->assertSessionHasErrors('action');
        $this->patch(route('users.update', $first), ['action' => 'deactivate'])->assertSessionHasNoErrors();
    }

    public function test_new_requests_only_notify_admins_and_staff_list_excludes_customers(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'created_by' => $customer->id]);
        app(NotifyOrderParticipants::class)->handle($order, 'Nuova richiesta', $customer->id);
        $this->assertSame(0, $rider->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $channels = collect((new WorkspaceUpdated($order->id, 'order'))->broadcastOn())->map(fn ($channel) => $channel->name)->all();
        $this->assertNotContains('private-staff.'.$rider->id, $channels);
        $this->actingAs($admin)->get('/settings/users')->assertSee($rider->email)->assertDontSee($customer->email);
    }

    public function test_label_uses_saved_total_and_sender_precedes_recipient(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted, 'price_cents' => 1250, 'parcel_value_cents' => 5000]);
        if ($directory = getenv('EA_RIDER_UI_CAPTURE')) {
            file_put_contents($directory.'/labels.html', $this->actingAs($rider)->get(route('labels.index', ['ids' => [$order->id]]))->getContent());
        }
        $this->actingAs($rider)->get(route('labels.index', ['ids' => [$order->id]]))->assertSeeInOrder(['Mittente / ritiro', 'Destinatario', 'Informazioni operative', 'Totale finale', '62,50']);
    }
}
