<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_can_create_request_without_injecting_assignment_or_tracking(): void
    {
        $rider = User::factory()->create();
        $data = Order::factory()->make()->only(['store_name', 'recipient_name', 'recipient_phone', 'pickup_address', 'pickup_city', 'delivery_address', 'delivery_city', 'pickup_date', 'pickup_from', 'pickup_to', 'parcel_count', 'category', 'urgency']);
        $data['pickup_date'] = now()->addDay()->toDateString();
        $data += ['status' => 'delivered', 'price_cents' => 1, 'rider_id' => 999, 'tracking_token' => 'guess'];
        $response = $this->actingAs($rider)->post('/orders', $data);
        $response->assertSessionHasNoErrors();
        $order = Order::sole();
        $response->assertRedirect(route('orders.show', $order));
        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame($rider->id, $order->created_by);
        $this->assertNull($order->rider_id);
        $this->assertNull($order->price_cents);
        $this->assertSame(64, strlen($order->tracking_token));
        $this->assertDatabaseCount('order_events', 1);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Accetta')->assertSee('Rifiuta');
    }

    public function test_full_delivery_workflow_persists_events_price_and_public_tracking(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create();
        $this->actingAs($rider);
        foreach (['accepted', 'pickup_scheduled', 'rider_arriving', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'] as $status) {
            $this->patch(route('orders.update', $order), ['status' => $status, 'version' => $order->version, 'price' => '12,35', 'note' => 'Informazione riservata', 'public_note' => 'Aggiornamento pubblico'])->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order));
            $order->refresh();
        }
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(1235, $order->price_cents);
        $this->assertSame($rider->id, $order->rider_id);
        $this->assertNotNull($order->tracking_started_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertDatabaseCount('order_events', 7);
        $this->get(route('tracking.public', $order->tracking_token))->assertOk()->assertSee('Consegnato')->assertSee('Aggiornamento pubblico')->assertDontSee('Informazione riservata')->assertDontSee($order->recipient_name)->assertDontSee($order->recipient_phone)->assertDontSee($order->delivery_address)->assertDontSee($order->store_name)->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_tracking_is_unavailable_before_start_and_for_invalid_tokens(): void
    {
        $order = Order::factory()->create();
        $this->get(route('tracking.public', $order->tracking_token))->assertNotFound();
        $this->get('/track/123')->assertNotFound();
    }

    public function test_other_rider_cannot_change_or_read_assigned_order(): void
    {
        $other = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => User::factory(), 'status' => OrderStatus::Accepted]);
        $this->actingAs($other)->get(route('orders.show', $order))->assertNotFound();
        $this->patch(route('orders.update', $order), ['status' => 'rider_arriving', 'version' => 1])->assertForbidden();
        $this->get('/orders/in-progress')->assertDontSee($order->reference);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_stale_or_skipped_transitions_do_not_mutate_orders(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        $this->actingAs($user)->patch(route('orders.update', $order), ['status' => 'delivered', 'version' => 1])->assertSessionHasErrors('status');
        $this->patch(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '5.00'])->assertSessionHasNoErrors();
        $this->patch(route('orders.update', $order), ['status' => 'rider_arriving', 'version' => 1])->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertDatabaseCount('order_events', 1);
    }

    public function test_rejection_requires_reason_and_is_recoverable_only_within_an_hour(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create();
        $this->actingAs($user)->patch(route('orders.update', $order), ['status' => 'rejected', 'version' => 1])->assertSessionHasErrors('note');
        $this->patch(route('orders.update', $order), ['status' => 'rejected', 'version' => 1, 'note' => 'Nessuna disponibilità'])->assertSessionHasNoErrors();
        $this->travel(59)->minutes();
        $this->patch(route('orders.update', $order), ['status' => 'received', 'version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->patch(route('orders.update', $order), ['status' => 'rejected', 'version' => 3, 'note' => 'Nessuna disponibilità'])->assertSessionHasNoErrors();
        $this->travel(61)->minutes();
        $this->patch(route('orders.update', $order), ['status' => 'received', 'version' => 4])->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::Rejected, $order->fresh()->status);
    }

    public function test_exception_can_be_rescheduled_with_reason_and_new_estimate(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::OutForDelivery, 'tracking_started_at' => now()]);
        $this->actingAs($user)->patch(route('orders.update', $order), ['status' => 'delivery_attempted', 'version' => 1, 'note' => 'Destinatario assente'])->assertSessionHasNoErrors();
        $this->patch(route('orders.update', $order), ['status' => 'rescheduled', 'version' => 2, 'note' => 'Accordo telefonico'])->assertSessionHasErrors('estimated_at');
        $this->patch(route('orders.update', $order), ['status' => 'rescheduled', 'version' => 2, 'note' => 'Accordo telefonico', 'estimated_at' => now('Europe/Rome')->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Rescheduled, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->estimated_at);
    }

    public function test_bad_order_input_and_price_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->post('/orders', [])->assertSessionHasErrors(['store_name', 'recipient_phone', 'pickup_date', 'parcel_count']);
        $order = Order::factory()->create();
        $this->patch(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '-0.01'])->assertSessionHasErrors('price');
        $this->patch(route('orders.update', $order), ['status' => 'accepted', 'version' => 1, 'price' => '12.345'])->assertSessionHasErrors('price');
        $this->assertNull($order->fresh()->price_cents);
    }

    public function test_history_filters_closed_orders_by_customer_zone_and_period(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $match = Order::factory()->create(['store_name' => 'Negozio Centro', 'status' => OrderStatus::Delivered]);
        $other = Order::factory()->create(['status' => OrderStatus::Cancelled, 'delivery_city' => 'Salerno']);
        $old = Order::factory()->create(['status' => OrderStatus::Delivered, 'created_at' => now()->subMonths(13)]);
        $this->actingAs($user)->get('/orders/history?zone=Caserta&q=Centro&status=delivered')->assertOk()->assertSee($match->reference)->assertDontSee($other->reference)->assertDontSee($old->reference);
    }

    public function test_operational_history_prevents_account_deletion(): void
    {
        $user = User::factory()->create();
        Order::factory()->create(['created_by' => $user->id]);
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertModelExists($user);
    }

    public function test_rescheduled_delivery_cannot_skip_a_pickup_that_never_happened(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::Rescheduled]);
        $this->actingAs($user)->patch(route('orders.update', $order), ['status' => 'out_for_delivery', 'version' => 1])->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::Rescheduled, $order->fresh()->status);
        $this->patch(route('orders.update', $order), ['status' => 'rider_arriving', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::RiderArriving, $order->fresh()->status);
    }

    public function test_history_accepts_end_date_alone_and_uses_italian_day_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 8));
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $first = Order::factory()->create(['status' => OrderStatus::Delivered, 'created_at' => '2026-09-06 22:30:00']);
        $second = Order::factory()->create(['status' => OrderStatus::Delivered, 'created_at' => '2026-09-07 22:30:00']);
        $this->actingAs($user)->get('/orders/history?to=2026-09-07')->assertOk()->assertSee($first->reference)->assertDontSee($second->reference);
        $this->get('/orders/history?from=2026-09-08&to=2026-09-07')->assertSessionHasErrors('to');
    }
}
