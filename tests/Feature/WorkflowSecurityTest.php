<?php

namespace Tests\Feature;

use App\Actions\NotifyAccountingParticipants;
use App\Actions\TransitionOrder;
use App\Http\Controllers\PaymentController;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(array $attributes = []): Order
    {
        return Order::factory()->create([
            'rider_id' => User::factory(), 'customer_id' => User::factory()->create(['role' => UserRole::Customer])->id,
            'shipping_type' => 'external', 'price_cents' => 600, 'quoted_price_cents' => 600,
            'carrier_cost_cents' => 400, 'pricing_version' => 1, 'price_state' => 'agreed',
            'status' => OrderStatus::OutForDelivery, ...$attributes,
        ]);
    }

    public function test_assigned_rider_cannot_record_or_forge_external_receipts(): void
    {
        $order = $this->order(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        $this->actingAs($order->rider)->postJson('/balance/'.$order->id.'/payment', [
            'action' => 'receive', 'version' => 1, 'received_amount' => '999999', 'role' => 'admin', 'user_id' => 1, 'ea_amount' => '999999', 'paid_at' => now()->toIso8601String(),
        ])->assertForbidden();
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(600, $order->fresh()->price_cents);
    }

    public function test_admin_cannot_receive_before_delivery_or_replay_a_receipt(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $path = '/balance/'.$order->id.'/payment';
        $data = ['action' => 'receive', 'version' => 1, 'received_amount' => '6'];
        $this->actingAs($admin)->postJson($path, $data)->assertUnprocessable();
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertNull($order->fresh()->paid_at);
        $this->patchJson('/orders/'.$order->id, ['status' => 'delivered', 'version' => 1])->assertRedirect();
        $data['version'] = 2;
        $this->postJson($path, [...$data, 'price_cents' => 1, 'carrier_cost_cents' => 1, 'ea_amount_cents' => 99999])->assertOk();
        $this->postJson($path, $data)->assertUnprocessable();
        $this->postJson($path, [...$data, 'version' => 3])->assertUnprocessable();
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertSame(600, PaymentEntry::sole()->amount_cents);
        $this->assertSame(600, PaymentEntry::sole()->ea_amount_cents);
        $this->assertSame(400, $order->fresh()->carrier_cost_cents);
    }

    public static function unresolvedPrices(): array
    {
        return ['rejected' => ['rejected', 'rejected'], 'pending' => ['awaiting_customer', 'pending'], 'inconsistent summary' => ['agreed', 'rejected']];
    }

    #[DataProvider('unresolvedPrices')]
    public function test_unresolved_proposal_prevents_delivery_receipt_and_pending_settlement(string $priceState, string $proposalState): void
    {
        $order = $this->order(['price_state' => $priceState]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order->priceProposals()->create(['proposed_by' => $admin->id, 'price_cents' => 600, 'previous_price_cents' => 500, 'reason' => 'Test', 'state' => $proposalState]);
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id, ['status' => 'delivered', 'version' => 1])->assertConflict();
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertDatabaseCount('order_events', 0);
        $order->status = OrderStatus::Delivered;
        $order->delivered_at = now();
        $order->save();
        $this->actingAs($admin)->postJson('/balance/'.$order->id.'/payment', ['action' => 'receive', 'version' => 1, 'received_amount' => '6'])->assertConflict();
        $account = PendingAccount::factory()->create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'direction' => 'incoming', 'amount_cents' => 600]);
        $this->postJson('/pending/'.$account->id.'/settlements', ['version' => 1, 'amount' => '6', 'submission_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('pending_settlements', 0);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_rejected_proposal_cannot_be_reconfirmed_during_acceptance(): void
    {
        $order = $this->order(['status' => OrderStatus::Received, 'rider_id' => null]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/price', ['action' => 'propose', 'version' => 1, 'price' => '6', 'reason' => 'Nuova tariffa'])->assertOk();
        $this->actingAs($order->customer, 'customer')->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'reject', 'version' => 2])->assertOk();
        $this->actingAs($admin, 'web')->patchJson('/orders/'.$order->id, ['status' => 'accepted', 'version' => 3, 'rider_id' => $rider->id, 'price' => '6'])->assertConflict();
        $this->assertSame('rejected', $order->fresh()->price_state);
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_customer_accepted_proposal_allows_the_order_workflow(): void
    {
        $order = $this->order(['status' => OrderStatus::Received, 'rider_id' => null]);
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/price', ['action' => 'propose', 'version' => 1, 'price' => '8', 'reason' => 'Nuova tariffa'])->assertOk();
        $this->actingAs($order->customer, 'customer')->patchJson('/api/v1/customer/orders/'.$order->id.'/price', ['action' => 'accept', 'version' => 2])->assertOk();
        $this->actingAs($admin, 'web')->patchJson('/orders/'.$order->id, ['status' => 'accepted', 'version' => 3, 'rider_id' => $rider->id])->assertRedirect();
        $this->actingAs($rider, 'web');
        foreach (['rider_arriving', 'picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->patchJson('/orders/'.$order->id, ['status' => $status, 'version' => $order->fresh()->version])->assertRedirect();
        }
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(800, $order->fresh()->price_cents);
    }

    public function test_eta_is_rejected_outside_rescheduling_including_the_carrier_endpoint(): void
    {
        $order = $this->order();
        $eta = now('Europe/Rome')->addDay()->format('Y-m-d\TH:i');
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id, ['status' => 'delivered', 'version' => 1, 'estimated_at' => $eta])->assertUnprocessable()->assertJsonValidationErrors('estimated_at');
        $this->patchJson('/orders/'.$order->id.'/carrier', ['version' => 1, 'carrier_tracking' => 'TRACK', 'carrier_status' => 'booked', 'estimated_at' => $eta])->assertUnprocessable()->assertJsonValidationErrors('estimated_at');
        $this->assertNull($order->fresh()->estimated_at);
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
    }

    public function test_eta_invariant_is_enforced_when_the_action_is_called_directly(): void
    {
        $order = $this->order();
        try {
            app(TransitionOrder::class)->handle($order, $order->rider, ['status' => 'delivered', 'version' => 1, 'estimated_at' => now()->addDay()->format('Y-m-d\TH:i')]);
            $this->fail('The action accepted an ETA outside rescheduling.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('estimated_at', $exception->errors());
        }
        $this->assertNull($order->fresh()->estimated_at);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_rescheduling_requires_a_future_eta_and_clears_it_on_the_next_transition(): void
    {
        $order = $this->order(['status' => OrderStatus::DeliveryAttempted]);
        $order->events()->create(['user_id' => $order->rider_id, 'status' => OrderStatus::PickedUp]);
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id, ['status' => 'rescheduled', 'version' => 1, 'note' => 'Nuovo appuntamento'])->assertUnprocessable();
        $this->patchJson('/orders/'.$order->id, ['status' => 'rescheduled', 'version' => 1, 'note' => 'Nuovo appuntamento', 'estimated_at' => now('Europe/Rome')->addDay()->format('Y-m-d\TH:i')])->assertRedirect();
        $this->assertNotNull($order->fresh()->estimated_at);
        $this->patchJson('/orders/'.$order->id, ['status' => 'out_for_delivery', 'version' => 2])->assertRedirect();
        $this->assertNull($order->fresh()->estimated_at);
    }

    public function test_skipped_steps_wrong_rider_and_stale_versions_cannot_mutate_orders(): void
    {
        $order = $this->order(['status' => OrderStatus::Received]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->patchJson('/orders/'.$order->id, ['status' => 'delivered', 'version' => 1])->assertUnprocessable();
        $order->status = OrderStatus::OutForDelivery;
        $order->save();
        $this->actingAs(User::factory()->create())->patchJson('/orders/'.$order->id, ['status' => 'delivered', 'version' => 1])->assertForbidden();
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id, ['status' => 'delivered', 'version' => 1])->assertRedirect();
        $this->patchJson('/orders/'.$order->id, ['status' => 'delivery_issue', 'version' => 1, 'note' => 'Replay'])->assertUnprocessable();
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertDatabaseCount('order_events', 1);
    }

    public function test_month_end_receipts_and_expenses_remain_in_the_financial_report(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->postJson('/expenses', ['description' => 'Spesa di fine mese', 'amount' => '10', 'spent_on' => '2026-09-30'])->assertCreated();
        $this->get('/reports?month=2026-09')->assertOk()->assertViewHas('spent', 1000);
    }

    public function test_payment_authorization_does_not_depend_on_the_route_middleware(): void
    {
        $order = $this->order(['status' => OrderStatus::Delivered]);
        $request = Request::create('/balance/'.$order->id.'/payment', 'POST', ['action' => 'receive', 'version' => 1, 'received_amount' => '6']);
        $request->setUserResolver(fn () => $order->rider);
        try {
            app(PaymentController::class)->store($request, $order, app(NotifyAccountingParticipants::class));
            $this->fail('A rider recorded a receipt without route middleware.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertNull($order->fresh()->paid_at);
    }

    public static function unapprovedAcceptance(): array
    {
        return [
            'pending' => ['awaiting_customer', 'pending', 600],
            'rejected with quote retained' => ['rejected', 'rejected', 600],
            'accepted price mismatch' => ['agreed', 'accepted', 700],
        ];
    }

    #[DataProvider('unapprovedAcceptance')]
    public function test_acceptance_cannot_override_the_customer_price_decision(string $state, string $proposalState, int $proposalPrice): void
    {
        $order = $this->order(['status' => OrderStatus::Received, 'price_state' => $state]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $proposal = $order->priceProposals()->create(['proposed_by' => $admin->id, 'price_cents' => $proposalPrice, 'previous_price_cents' => 500, 'reason' => 'Test', 'state' => $proposalState]);
        $before = $order->fresh()->getAttributes();
        $data = ['status' => 'accepted', 'version' => 1, 'rider_id' => $order->rider_id, 'price' => '0.01', 'price_state' => 'agreed', 'quoted_price_cents' => 1];
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id, [...$data, 'role' => 'admin'])->assertForbidden();
        $this->actingAs($admin)->patchJson('/orders/'.$order->id, $data)->assertConflict();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($proposalState, $proposal->fresh()->state);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    public static function nonRescheduledTransitions(): array
    {
        return [
            'delivered' => [OrderStatus::OutForDelivery, 'delivered'],
            'cancelled' => [OrderStatus::DeliveryAttempted, 'cancelled'],
            'out for delivery' => [OrderStatus::PickedUp, 'out_for_delivery'],
        ];
    }

    #[DataProvider('nonRescheduledTransitions')]
    public function test_eta_cannot_be_written_by_http_or_direct_action_on_other_transitions(OrderStatus $current, string $next): void
    {
        $order = $this->order(['status' => $current]);
        $before = $order->fresh()->getAttributes();
        $data = ['status' => $next, 'version' => 1, 'note' => 'Test', 'estimated_at' => now('Europe/Rome')->addDay()->format('Y-m-d\TH:i')];
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id, $data)->assertUnprocessable()->assertJsonValidationErrors('estimated_at');
        try {
            app(TransitionOrder::class)->handle($order, $order->rider, $data);
            $this->fail('An ETA was accepted outside rescheduling.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('estimated_at', $exception->errors());
        }
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_pickup_schedule_ignores_an_injected_delivery_eta(): void
    {
        $order = $this->order(['status' => OrderStatus::Accepted]);
        $this->actingAs($order->rider)->patchJson('/orders/'.$order->id.'/pickup-schedule', [
            'version' => 1, 'pickup_date' => now('Europe/Rome')->addDays(2)->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00',
            'reason' => 'Cambio appuntamento', 'estimated_at' => now('Europe/Rome')->addDays(3)->format('Y-m-d\TH:i'),
        ])->assertRedirect();
        $this->assertNull($order->fresh()->estimated_at);
        $this->assertSame(OrderStatus::PickupScheduled, $order->fresh()->status);
    }
}
