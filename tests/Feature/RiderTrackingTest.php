<?php

namespace Tests\Feature;

use App\Events\RiderLocationUpdated;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\RiderTracking;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): Order
    {
        return Order::factory()->create([...['rider_id' => User::factory(), 'customer_id' => User::factory()->state(['role' => UserRole::Customer]), 'status' => OrderStatus::OutForDelivery, 'tracking_started_at' => now(), 'price_cents' => 500], ...$attributes]);
    }

    private function start(Order $order): string
    {
        return $this->actingAs($order->rider, 'web')->postJson('/orders/'.$order->id.'/location/session')->assertOk()->json('session');
    }

    private function position(string $session, array $overrides = []): array
    {
        return [...['session' => $session, 'latitude' => 40.8518, 'longitude' => 14.2681, 'accuracy' => 12, 'recorded_at' => now()->toIso8601String()], ...$overrides];
    }

    public function test_real_position_is_visible_only_to_owner_assigned_rider_and_admin(): void
    {
        $this->freezeTime();
        $order = $this->order();
        Event::fake([RiderLocationUpdated::class]);
        $session = $this->start($order);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->getJson('/orders/'.$order->id.'/location')->assertOk()->assertJsonPath('location.latitude', 40.8518)->assertJsonPath('state', 'live')->assertJsonMissingPath('session');
        $this->actingAs($order->customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->id.'/location')->assertOk()->assertJsonPath('location.longitude', 14.2681);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->getJson('/api/v1/customer/orders/'.$order->id.'/location')->assertNotFound();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/orders/'.$order->id.'/location')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web')->getJson('/orders/'.$order->id.'/location')->assertOk();
        Event::assertDispatched(RiderLocationUpdated::class, fn ($event) => $event->orderId === $order->id);
        $this->assertSame(['order_id' => $order->id], (new RiderLocationUpdated($order->id))->broadcastWith());
        $this->assertArrayNotHasKey('gps_session_id', $order->fresh()->toArray());
    }

    public function test_anonymous_and_other_riders_cannot_read_or_write_gps(): void
    {
        $order = $this->order();
        $this->getJson('/api/v1/customer/orders/'.$order->id.'/location')->assertUnauthorized();
        $this->postJson('/orders/'.$order->id.'/location/session')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/orders/'.$order->id.'/location/session')->assertNotFound();
        $this->putJson('/orders/'.$order->id.'/location', $this->position((string) Str::uuid()))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->postJson('/orders/'.$order->id.'/location/session')->assertNotFound();
    }

    public static function inactiveStates(): array
    {
        return array_map(fn ($status) => [$status], ['received', 'accepted', 'pickup_scheduled', 'delivered', 'rejected', 'cancelled', 'delivery_issue', 'delivery_attempted', 'rescheduled']);
    }

    #[DataProvider('inactiveStates')]
    public function test_gps_cannot_start_outside_operational_states(string $status): void
    {
        $order = $this->order(['status' => $status]);
        $this->actingAs($order->rider)->postJson('/orders/'.$order->id.'/location/session')->assertConflict();
    }

    public function test_stop_removes_coordinates_and_rejects_previous_session(): void
    {
        $order = $this->order();
        $session = $this->start($order);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->deleteJson('/orders/'.$order->id.'/location/session', ['session' => $session])->assertOk();
        $this->assertNull(app(RiderTracking::class)->cache()->get('gps:'.$session));
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertConflict();
        $this->getJson('/orders/'.$order->id.'/location')->assertJsonPath('location', null)->assertJsonPath('state', 'ready');
    }

    public function test_new_session_replaces_previous_order_and_prevents_late_stop_from_old_device(): void
    {
        $order = $this->order();
        $old = $this->start($order);
        $next = $this->order(['rider_id' => $order->rider_id]);
        $new = $this->start($next);
        $this->assertNull($order->fresh()->gps_session_id);
        $this->deleteJson('/orders/'.$next->id.'/location/session', ['session' => $old])->assertConflict();
        $this->assertSame($new, $next->fresh()->gps_session_id);
    }

    public function test_delivery_and_reassignment_revoke_position_access(): void
    {
        $order = $this->order();
        $session = $this->start($order);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->patchJson('/orders/'.$order->id, ['version' => 1, 'status' => 'delivered'])->assertRedirect();
        $this->assertNull($order->fresh()->gps_session_id);
        $this->getJson('/orders/'.$order->id.'/location')->assertJsonPath('state', 'completed')->assertJsonPath('location', null);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertConflict();
        $other = $this->order();
        $old = $this->start($other);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->patch('/orders/'.$other->id.'/rider', ['version' => 1, 'rider_id' => User::factory()->create()->id])->assertRedirect();
        $this->assertNull($other->fresh()->gps_session_id);
        $this->actingAs($other->rider)->putJson('/orders/'.$other->id.'/location', $this->position($old))->assertNotFound();
    }

    public function test_stale_location_is_retained_temporarily_then_expires(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $session = $this->start($order);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->travel(2)->minutes();
        $this->getJson('/orders/'.$order->id.'/location')->assertJsonPath('state', 'stale')->assertJsonPath('location.latitude', 40.8518);
        $this->travel(14)->minutes();
        $this->getJson('/orders/'.$order->id.'/location')->assertJsonPath('location', null)->assertJsonPath('state', 'locating');
    }

    public function test_invalid_old_future_and_out_of_order_positions_are_rejected(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $session = $this->start($order);
        foreach ([['latitude' => 91], ['longitude' => -181], ['accuracy' => 501], ['recorded_at' => now()->subMinutes(3)->toIso8601String()], ['recorded_at' => now()->addMinute()->toIso8601String()]] as $invalid) {
            $this->putJson('/orders/'.$order->id.'/location', $this->position($session, $invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $valid = $this->position($session);
        $this->putJson('/orders/'.$order->id.'/location', $valid)->assertOk();
        $this->travel(6)->seconds();
        $this->putJson('/orders/'.$order->id.'/location', $valid)->assertConflict();
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->travel(1)->seconds();
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertTooManyRequests();
    }

    public function test_public_tracking_has_no_location_and_carrier_or_disabled_rider_has_no_gps(): void
    {
        $order = $this->order();
        $session = $this->start($order);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->get('/track/'.$order->tracking_token)->assertOk()->assertDontSee('40.8518');
        $rider = $order->rider;
        $rider->is_active = false;
        $rider->save();
        $this->actingAs($order->customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->id.'/location')->assertJsonPath('location', null);
        $order->carrier_handed_at = now();
        $order->save();
        $this->getJson('/api/v1/customer/orders/'.$order->id.'/location')->assertJsonPath('state', 'carrier')->assertJsonPath('location', null);
    }

    public function test_map_points_require_staff_ownership_and_current_version(): void
    {
        $order = $this->order();
        $data = ['kind' => 'pickup', 'latitude' => 40.85, 'longitude' => 14.26, 'version' => 1];
        $this->actingAs(User::factory()->create())->patchJson('/orders/'.$order->id.'/map-points', $data)->assertNotFound();
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id.'/map-points', $data)->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(['latitude' => 40.85, 'longitude' => 14.26], $order->fresh()->pickup_point);
        $this->patchJson('/orders/'.$order->id.'/map-points', $data)->assertConflict();
        $this->getJson('/orders/'.$order->id.'/location')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        $this->actingAs($order->customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->id.'/location')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_logout_and_session_expiry_clear_tracking(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $session = $this->start($order);
        $this->putJson('/orders/'.$order->id.'/location', $this->position($session))->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertNull($order->fresh()->gps_session_id);
        $this->assertNull(app(RiderTracking::class)->cache()->get('gps:'.$session));
        $this->start($order);
        $this->travel(16)->minutes();
        app(RiderTracking::class)->expireInactive();
        $this->assertNull($order->fresh()->gps_session_id);
    }

    public function test_operational_pages_render_and_customer_dashboard_selects_only_own_order(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Rider in strada');
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Segui la consegna');
        $this->get('/orders/in-progress')->assertOk()->assertSee($order->reference);
        $this->get('/tracking')->assertOk()->assertSee('Mappa live');
        $this->actingAs($order->rider)->get('/dashboard')->assertOk()->assertSee('data-rider-gps', false);
        $this->actingAs($order->customer, 'customer')->getJson('/api/v1/customer/dashboard')->assertOk()->assertJsonPath('live_order.id', $order->id);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->getJson('/api/v1/customer/dashboard')->assertOk()->assertJsonPath('live_order', null);
    }
}
