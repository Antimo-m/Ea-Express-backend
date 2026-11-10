<?php

namespace Tests\Feature;

use App\Actions\NotifyOrderParticipants;
use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalNotificationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_progress_reaches_the_customer_without_notifying_supervisors_or_unrelated_riders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted, 'price_cents' => 850]);

        $this->actingAs($rider)->patchJson(route('orders.update', $order), ['status' => 'rider_arriving', 'version' => 1])->assertOk();

        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->assertSame(0, $rider->notifications()->count());
        $this->assertSame(1, $customer->notifications()->where('data->title', 'Rider in arrivo')->count());
        $this->actingAs($admin)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'items');
        $this->get(route('orders.show', $order))->assertOk()->assertSee($order->reference);
    }

    public function test_administrative_decisions_and_customer_messages_use_the_actual_responsibility(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $incoming = Order::factory()->create(['customer_id' => $customer->id]);
        $assigned = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $notify = app(NotifyOrderParticipants::class);

        $notify->handle($incoming, 'Nuova richiesta', $customer->id);
        $notify->handle($assigned, 'Nuovo messaggio dal cliente', $customer->id, true);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('Nuova richiesta', $admin->notifications()->sole()->data['title']);
        $this->assertSame(1, $rider->notifications()->count());
        $this->assertSame('Nuovo messaggio dal cliente', $rider->notifications()->sole()->data['title']);
        $this->assertSame(0, $other->notifications()->count());

        $assigned->status = OrderStatus::DeliveryIssue;
        $assigned->save();
        $notify->handle($assigned, 'Problema nella consegna', $rider->id);

        $this->assertSame(2, $admin->notifications()->count());
        $this->assertSame(1, $customer->notifications()->where('data->title', 'Problema nella consegna')->count());
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_an_admin_assigned_to_an_order_receives_personal_operational_notifications(): void
    {
        $assignedAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $supervisor = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $assignedAdmin->id, 'status' => OrderStatus::Accepted]);

        app(NotifyOrderParticipants::class)->handle($order, 'Nuovo messaggio dal cliente', $customer->id, true);

        $this->assertSame(1, $assignedAdmin->notifications()->count());
        $this->assertSame(0, $supervisor->notifications()->count());
    }

    /** @return array<string, array{OrderStatus}> */
    public static function administrativeStatuses(): array
    {
        return [
            'failed attempt' => [OrderStatus::DeliveryAttempted],
            'delivery issue' => [OrderStatus::DeliveryIssue],
            'rescheduled' => [OrderStatus::Rescheduled],
            'cancelled' => [OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('administrativeStatuses')]
    public function test_events_requiring_a_decision_still_reach_the_admin(OrderStatus $status): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => $status]);

        app(NotifyOrderParticipants::class)->handle($order, $status->label(), $rider->id);

        $this->assertSame(1, $admin->notifications()->where('data->title', $status->label())->count());
    }

    public function test_carrier_issues_still_reach_the_admin_without_notifying_other_riders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'shipping_type' => 'external', 'status' => OrderStatus::InTransit, 'carrier_name' => 'Vettore', 'carrier_status' => 'in_transit', 'price_cents' => 1000]);
        $order->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $rider->id]);

        $this->actingAs($rider)->patch(route('orders.carrier', $order), ['version' => 1, 'carrier_tracking' => 'TRACK123', 'carrier_status' => 'delivery_issue'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('delivery_issue', $order->fresh()->carrier_status);
        $this->assertSame(1, $admin->notifications()->where('data->title', 'Problema di consegna del vettore')->count());
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_reassignment_revokes_old_feed_history_and_queued_realtime_delivery(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $first->id, 'status' => OrderStatus::Accepted]);
        $notify = app(NotifyOrderParticipants::class);
        $notify->handle($order, 'Prima assegnazione', $admin->id);
        $queuedBeforeReassignment = new WorkspaceUpdated($order->id, 'order');
        $this->actingAs($first)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 1);

        $this->actingAs($admin)->patchJson(route('orders.rider', $order), ['rider_id' => $second->slug, 'version' => 1])->assertOk();
        $notify->handle($order->refresh(), 'Aggiornamento successivo', $admin->id);

        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(2, $second->notifications()->count());
        $this->actingAs($first)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'items');
        $this->getJson(route('notifications.history', $order))->assertNotFound();
        $this->get(route('notifications.index'))->assertOk()->assertDontSee($order->reference);
        $channels = array_map(fn ($channel): string => $channel->name, $queuedBeforeReassignment->broadcastOn());
        $this->assertNotContains('private-staff.'.$first->slug, $channels);
        $this->assertContains('private-staff.'.$second->slug, $channels);
        $this->assertContains('private-staff.'.$admin->slug, $channels);
    }

    public function test_private_realtime_channels_reject_other_users_and_numeric_identifiers(): void
    {
        config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($rider)->postJson('/realtime/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-staff.'.$rider->slug])->assertOk()->assertJsonStructure(['auth']);
        foreach ([$other->slug, $admin->slug, $rider->id] as $reference) {
            $this->postJson('/realtime/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-staff.'.$reference])->assertForbidden();
        }
    }
}
