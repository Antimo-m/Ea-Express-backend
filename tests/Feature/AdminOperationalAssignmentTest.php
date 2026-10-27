<?php

namespace Tests\Feature;

use App\Actions\TransitionOrder;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminOperationalAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_accept_for_self_and_complete_pickup_tracking_and_delivery(): void
    {
        $this->freezeTime();
        $admin = User::factory()->unverified()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Received, 'pricing_version' => 1, 'price_state' => 'agreed', 'price_cents' => 12550, 'quoted_price_cents' => 12550]);

        $this->actingAs($admin)->get('/orders/'.$order->slug)->assertViewHas('riders', fn ($riders) => $riders->contains('id', $admin->id));
        $this->patchJson('/orders/'.$order->slug, ['status' => 'accepted', 'rider_id' => $admin->slug, 'version' => 1])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'rider_id' => $admin->id, 'assigned_by' => $admin->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'rider.assigned']);
        $this->patchJson('/orders/'.$order->slug, ['status' => 'rider_arriving', 'version' => 2])->assertOk();
        $this->get('/orders/'.$order->slug)->assertDontSee('data-gps-start', false)->assertSee('Il percorso dell’ordine')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();
        foreach (['picked_up' => 3, 'in_transit' => 4, 'out_for_delivery' => 5, 'delivered' => 6] as $status => $version) {
            $this->patchJson('/orders/'.$order->slug, ['status' => $status, 'version' => $version])->assertOk();
        }

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertNull($order->fresh()->gps_session_id);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'user_id' => $admin->id, 'status' => 'delivered']);
    }

    public function test_reassignment_to_self_and_logout_work_without_geolocation(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery, 'rider_id' => $rider->id]);
        $this->actingAs($rider)->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug.'/rider', ['rider_id' => $admin->slug, 'version' => 1])->assertOk();
        $this->assertNull($order->fresh()->gps_session_id);
        $this->assertSame($admin->id, $order->fresh()->rider_id);
        $this->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();
        $this->post('/logout')->assertRedirect();
        $this->assertNull($order->fresh()->gps_session_id);
    }

    public function test_other_admin_customer_and_inactive_rider_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targets = [User::factory()->create(['role' => UserRole::Admin]), User::factory()->create(['role' => UserRole::Customer]), User::factory()->create(['is_active' => false])];
        $order = Order::factory()->create(['status' => OrderStatus::Accepted]);
        $this->actingAs($admin);
        foreach ($targets as $target) {
            $this->patchJson('/orders/'.$order->slug.'/rider', ['rider_id' => $target->slug, 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('rider_id');
        }
        $this->assertNull($order->fresh()->rider_id);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    public function test_direct_acceptance_cannot_assign_a_different_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Received]);
        try {
            app(TransitionOrder::class)->handle($order, $admin, ['status' => 'accepted', 'rider_id' => $other->id, 'version' => 1]);
            $this->fail('Another administrator was assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rider_id', $exception->errors());
        }
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_admin_with_active_assignments_cannot_change_operational_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['status' => OrderStatus::Accepted, 'rider_id' => $admin->id]);

        $this->artisan('app:user-role', ['email' => $admin->email, 'role' => 'customer'])
            ->expectsOutput('Riassegna le spedizioni attive prima di cambiare ruolo.')
            ->assertFailed();
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admin_cannot_share_gps_for_an_order_assigned_to_someone_else(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::OutForDelivery, 'rider_id' => User::factory()]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();
        $this->assertNull($order->fresh()->gps_session_id);
    }
}
