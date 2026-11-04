<?php

namespace Tests\Feature;

use App\Actions\ReportOrderDelay;
use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Policies\OrderPolicy;
use App\Support\DeliveryDeadline;
use App\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ManualOrderDelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_rider_reports_once_with_server_time_and_preserves_delivery_status(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 11:15:30', 'Europe/Rome'));
        $rider = User::factory()->create();
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::OutForDelivery]);
        Event::fake([WorkspaceUpdated::class]);

        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])
            ->assertOk()->assertJsonPath('status', 'out_for_delivery')->assertJsonPath('version', 2)
            ->assertJsonPath('delay.reported', true)->assertJsonPath('delay.reported_at', '2026-10-23T09:15:30+00:00');
        $this->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertOk()->assertJsonPath('message', 'Ritardo già segnalato.')->assertJsonPath('version', 2);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'out_for_delivery', 'version' => 2, 'delay_reported_by' => $rider->id, 'delay_reported_at' => '2026-10-23 09:15:30']);
        $this->assertDatabaseCount('order_events', 1);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'user_id' => $rider->id, 'delay_action' => 'reported', 'status' => 'out_for_delivery']);
        Event::assertDispatchedTimes(WorkspaceUpdated::class, 1);
    }

    public function test_guest_customer_unassigned_rider_and_numeric_reference_cannot_report(): void
    {
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->for($assigned, 'rider')->for($customer, 'customer')->create(['status' => OrderStatus::PickedUp]);

        $this->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertUnauthorized();
        $this->actingAs($other)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertForbidden();
        $this->actingAs($customer)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertForbidden();
        $this->actingAs($assigned)->postJson('/orders/'.$order->id.'/delay', ['version' => 1])->assertNotFound();
        $this->assertDatabaseCount('order_events', 0);
        $this->assertNull($order->fresh()->delay_reported_at);
    }

    #[TestWith(['received'])]
    #[TestWith(['delivered'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['rejected'])]
    public function test_report_rejects_non_operational_and_terminal_orders(string $status): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::from($status)]);
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertForbidden();
        $this->get('/orders/'.$order->slug)->assertDontSee('Segnala ritardo');
        $this->assertDatabaseCount('order_events', 0);
    }

    #[TestWith(['admin', true, true])]
    #[TestWith(['admin', false, false])]
    #[TestWith(['rider', true, true])]
    #[TestWith(['rider', false, false])]
    #[TestWith(['customer', true, false])]
    public function test_report_policy_requires_assigned_staff(string $role, bool $assigned, bool $allowed): void
    {
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $order = Order::factory()->make(['rider_id' => $assigned ? $user->id : null, 'status' => OrderStatus::OutForDelivery]);
        $this->assertSame($allowed, (new OrderPolicy)->reportDelay($user, $order));
        $this->assertSame($role === 'admin', (new OrderPolicy)->correctDelay($user, $order));
    }

    public function test_inactive_and_unverified_riders_cannot_report_in_policy(): void
    {
        $inactive = User::factory()->make(['id' => 1, 'is_active' => false]);
        $unverified = User::factory()->unverified()->make(['id' => 2]);
        $this->assertFalse((new OrderPolicy)->reportDelay($inactive, Order::factory()->make(['rider_id' => 1, 'status' => OrderStatus::PickedUp])));
        $this->assertFalse((new OrderPolicy)->reportDelay($unverified, Order::factory()->make(['rider_id' => 2, 'status' => OrderStatus::PickedUp])));
    }

    public function test_transaction_rechecks_assignment_after_form_was_opened(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->for($first, 'rider')->create(['status' => OrderStatus::PickedUp]);
        Order::whereKey($order->id)->update(['rider_id' => $second->id]);
        $this->expectException(AuthorizationException::class);
        try {
            app(ReportOrderDelay::class)->handle($order, $first, 1);
        } finally {
            $this->assertDatabaseCount('order_events', 0);
            $this->assertNull($order->fresh()->delay_reported_at);
        }
    }

    public function test_transaction_rechecks_terminal_status_after_form_was_opened(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::OutForDelivery]);
        Order::whereKey($order->id)->update(['status' => OrderStatus::Delivered]);
        $this->expectException(AuthorizationException::class);
        try {
            app(ReportOrderDelay::class)->handle($order, $rider, 1);
        } finally {
            $this->assertDatabaseCount('order_events', 0);
        }
    }

    public function test_stale_version_and_client_supplied_timestamps_are_rejected(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::PickedUp, 'version' => 2]);
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson('/orders/'.$order->slug.'/delay', ['version' => 2, 'delay_reported_at' => '2000-01-01', 'delay_reported_by' => $rider->slug])->assertUnprocessable()->assertJsonValidationErrors(['delay_reported_at', 'delay_reported_by']);
        $this->assertNull($order->fresh()->delay_reported_at);
    }

    public function test_admin_correction_preserves_history_reason_and_allows_new_report(): void
    {
        $this->freezeTime();
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::InTransit]);
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertOk();
        $this->patchJson('/orders/'.$order->slug.'/delay', ['version' => 2, 'reason' => 'Segnalazione errata'])->assertForbidden();
        $this->actingAs($admin)->patchJson('/orders/'.$order->slug.'/delay', ['version' => 2])->assertUnprocessable()->assertJsonValidationErrors('reason');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/delay-admin-correction.html', $this->get('/orders/'.$order->slug)->getContent());
        }
        $reason = 'Segnalazione errata <script>alert(1)</script>';
        $this->patchJson('/orders/'.$order->slug.'/delay', ['version' => 2, 'reason' => $reason])->assertOk()->assertJsonPath('delay.reported', false);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'delay_action' => 'reported', 'user_id' => $rider->id]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'delay_action' => 'corrected', 'note' => $reason, 'user_id' => $admin->id]);
        $this->get('/orders/'.$order->slug)->assertSee('Segnalazione di ritardo corretta')->assertSee(e($reason), false)->assertDontSee($reason, false);
        $this->assertNull($order->fresh()->delay_reported_at);
        $this->assertNull($order->fresh()->delay_reported_by);
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 3])->assertOk()->assertJsonPath('delay.reported', true);
        $this->assertDatabaseCount('order_events', 3);
        $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);
    }

    public function test_eta_alone_does_not_classify_delay_and_manual_report_needs_no_eta(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::OutForDelivery, 'pickup_date' => '2026-10-22', 'estimated_at' => now()->subDay()]);
        $this->assertFalse(app(DeliveryDeadline::class)->forOrder($order)['overdue']);
        $this->actingAs($admin)->getJson('/rider-operations/feed')->assertJsonPath('summary.overdue', 0);
        $order->forceFill(['estimated_at' => null, 'estimated_delivery_to' => null])->save();
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertOk();
        $this->actingAs($admin)->getJson('/rider-operations/feed')->assertJsonPath('summary.overdue', 1)->assertJsonPath('summary.backlog', 1);
        $this->get('/rider-operations/detail?scope=late')->assertSee('In ritardo')->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
    }

    public function test_report_is_visible_in_rider_dashboard_queue_detail_and_owner_api(): void
    {
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->for($rider, 'rider')->for($customer, 'customer')->create(['status' => OrderStatus::OutForDelivery, 'tracking_started_at' => now()]);
        $this->actingAs($rider);
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/delay-rider-unreported.html', $this->get('/orders/'.$order->slug)->getContent());
        }
        $this->post('/orders/'.$order->slug.'/delay', ['version' => 1])->assertRedirectToRoute('orders.show', $order);
        $this->get('/dashboard')->assertSee('In ritardo');
        $this->get('/track/'.$order->tracking_token)->assertOk()->assertSee('In ritardo')->assertSee('Ritardo segnalato dal Rider');
        $this->get('/orders/in-progress')->assertSee('In ritardo')->assertSee('Ritardo segnalato dal Rider');
        $detail = $this->get('/orders/'.$order->slug)->assertSee('In ritardo')->assertSee('Ritardo segnalato dal Rider')->assertDontSee('> Segnala ritardo</button>', false);
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/delay-rider-reported.html', $detail->getContent());
        }
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()->assertJsonPath('data.delay.reported', true)->assertJsonPath('data.status', 'out_for_delivery')->assertJsonPath('data.events.0.delay_action', 'reported')->assertJsonMissingPath('data.delay_reported_by');
        $this->actingAs($other, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertNotFound();
    }

    public function test_customer_attention_counts_active_reports_once_and_excludes_other_accounts_and_closed_reports(): void
    {
        $this->freezeTime();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->for($customer, 'customer')->create(['status' => OrderStatus::OutForDelivery, 'delay_reported_at' => now()]);
        Order::factory()->for($customer, 'customer')->create(['status' => OrderStatus::DeliveryIssue, 'delay_reported_at' => now()]);
        Order::factory()->for($customer, 'customer')->create(['status' => OrderStatus::Delivered, 'delay_reported_at' => now(), 'delivered_at' => now()]);
        Order::factory()->for($customer, 'customer')->create(['status' => OrderStatus::Cancelled, 'delay_reported_at' => now()]);
        Order::factory()->create(['status' => OrderStatus::OutForDelivery, 'delay_reported_at' => now()]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/dashboard')->assertOk()->assertJsonPath('metrics.attention', 2);
    }

    public function test_delay_reports_do_not_replace_rescheduling_deadlines_or_progress_timestamps(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 10:00', 'Europe/Rome'));
        $rider = User::factory()->create();
        $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::Rescheduled, 'estimated_at' => now()->addHours(3)]);
        $order->events()->create(['user_id' => $rider->id, 'status' => OrderStatus::Rescheduled, 'schedule_change' => ['before' => ['estimated_at' => null], 'after' => ['estimated_at' => $order->estimated_at->toIso8601String()]]]);
        $originalTime = $order->events()->sole()->created_at->toIso8601String();
        $this->travel(1)->hour();
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertOk();
        $deadline = app(DeliveryDeadline::class)->forOrder($order->fresh()->load(['scheduleEvents', 'events']));
        $this->assertSame('23/10/2026 13:00', $deadline['label']);
        $this->assertTrue($deadline['overdue']);
        $progress = $this->get('/orders/'.$order->slug)->assertOk()->viewData('progress');
        $this->assertSame($originalTime, $progress['exception']['at']->copy()->utc()->toIso8601String());
    }

    public function test_historical_report_survives_delivery_and_admin_correction_without_creating_delivery_events(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->for($admin, 'rider')->create(['status' => OrderStatus::OutForDelivery, 'pickup_date' => '2026-10-20', 'created_at' => now()->subDay()]);
        $this->actingAs($admin)->postJson('/orders/'.$order->slug.'/delay', ['version' => 1])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-21 12:00', 'Europe/Rome'));
        $this->patchJson('/orders/'.$order->slug, ['status' => 'delivered', 'version' => 2])->assertOk();
        $deliveryEvent = $order->fresh()->deliveryEvent;
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/delay-admin-terminal.html', $this->get('/orders/'.$order->slug)->getContent());
        }
        $this->travelTo(Carbon::parse('2026-10-23 12:00', 'Europe/Rome'));
        $this->patchJson('/orders/'.$order->slug.'/delay', ['version' => 3, 'reason' => 'Ritardo segnalato per errore'])->assertOk();
        $this->getJson('/rider-operations/feed?date=2026-10-20')->assertJsonPath('summary.overdue', 1);
        $this->getJson('/rider-operations/feed')->assertJsonPath('summary.overdue', 0);
        $this->assertSame($deliveryEvent->id, $order->fresh()->deliveryEvent->id);
        $this->get('/rider-operations/'.$admin->slug.'?date=2026-10-20')->assertViewHas('counts', fn ($counts) => $counts['completed'] === 1);
    }
}
