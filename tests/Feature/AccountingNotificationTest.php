<?php

namespace Tests\Feature;

use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_never_notify_customer_rider_or_tracking_with_only_the_admin_realtime_channel(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $colleague = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1000, 'tracking_started_at' => now()]);
        Event::fake([WorkspaceUpdated::class]);
        $this->actingAs($admin);
        foreach (['receive', 'reverse', 'refund', 'restore'] as $index => $action) {
            $this->postJson('/balance/'.$order->slug.'/payment', ['action' => $action, 'version' => $index + 1, 'note' => 'Rettifica contabile'])->assertOk();
        }
        $this->assertSame(0, $customer->notifications()->count());
        $this->assertSame(0, $rider->notifications()->count());
        $this->assertSame(0, $colleague->notifications()->count());
        $this->assertDatabaseCount('order_mail_deliveries', 0);
        Event::assertDispatchedTimes(WorkspaceUpdated::class, 4);
        Event::assertNotDispatched(WorkspaceUpdated::class, fn ($event) => $event->kind !== 'accounting');
        $channels = collect((new WorkspaceUpdated($order->id, 'accounting'))->broadcastOn())->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['private-staff.'.$admin->slug], $channels);
    }

    public function test_pending_settlement_and_correction_stay_internal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1000]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id, 'order_id' => $order->id]);
        Event::fake([WorkspaceUpdated::class]);
        $this->actingAs($admin)->postJson('/pending/'.$account->slug.'/settlements', ['version' => 1, 'amount' => '5', 'submission_key' => (string) Str::uuid()])->assertOk();
        $payment = $account->settlements()->sole();
        $this->patchJson('/pending/'.$account->slug.'/settlements/'.$payment->slug, ['version' => 2, 'amount' => '6', 'reason' => 'Rettifica ricevuta'])->assertOk();
        $this->assertSame(0, $customer->notifications()->count());
        $this->assertDatabaseCount('order_mail_deliveries', 0);
        Event::assertDispatchedTimes(WorkspaceUpdated::class, 2);
        Event::assertNotDispatched(WorkspaceUpdated::class, fn ($event) => $event->kind !== 'accounting');
    }

    public function test_operational_tracking_still_notifies_customer(): void
    {
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        Event::fake([WorkspaceUpdated::class]);
        $this->actingAs($rider)->patch(route('orders.update', $order), ['version' => 1, 'status' => 'rider_arriving'])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::RiderArriving, $order->fresh()->status);
        $this->assertSame(1, $customer->notifications()->count());
        Event::assertDispatched(WorkspaceUpdated::class, fn ($event) => $event->kind === 'order');
        $channels = collect((new WorkspaceUpdated($order->id, 'order'))->broadcastOn())->pluck('name')->all();
        $this->assertContains('private-customer.'.$customer->slug, $channels);
        $this->assertContains('private-tracking.'.hash('sha256', $order->tracking_token), $channels);
    }
}
