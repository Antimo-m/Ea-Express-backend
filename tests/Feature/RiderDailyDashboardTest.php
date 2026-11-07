<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RiderDailyDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'Europe/Rome'));
    }

    private function entry(Order $order, User $operator, int $amount, string $at, ?string $kind = null, ?int $cash = null): void
    {
        $entry = new PaymentEntry;
        $entry->order_id = $order->id;
        $entry->user_id = $operator->id;
        $entry->amount_cents = $amount;
        $entry->kind = $kind;
        $entry->cash_amount_cents = $cash;
        $entry->note = 'Registrazione contabile riservata';
        $entry->created_at = Carbon::parse($at, 'Europe/Rome')->utc();
        $entry->save();
    }

    public function test_default_day_uses_operational_timezone_and_never_includes_yesterday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 00:15', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $today = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'store_name' => 'Negozio di oggi']);
        $yesterday = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-07', 'store_name' => 'Negozio di ieri']);
        $yesterday->events()->create(['user_id' => $rider->id, 'rider_id' => $rider->id, 'status' => OrderStatus::DeliveryIssue, 'note' => 'Cronologia da non mostrare']);

        $this->actingAs($admin)->get('/rider-operations/'.$rider->slug)->assertOk()
            ->assertViewHas('period', fn ($period) => $period->start->toDateString() === '2026-10-08')
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('slug')->all() === [$today->slug])
            ->assertSee('Negozio di oggi')->assertDontSee('Negozio di ieri')->assertDontSee('Cronologia da non mostrare');
        $this->get('/rider-operations/'.$rider->slug.'?date=2026-10-07')->assertSee('Negozio di ieri')->assertDontSee('Negozio di oggi')->assertDontSee('Cronologia da non mostrare');
    }

    public function test_status_filter_paginates_without_changing_whole_day_counts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        Order::factory()->count(22)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::Accepted]);
        Order::factory()->count(3)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::OutForDelivery]);
        $completed = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::DeliveryIssue]);

        $this->actingAs($admin)->get('/rider-operations/'.$rider->slug.'?state=todo&page=2')->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 2 && $orders->total() === 22)
            ->assertViewHas('counts', fn ($counts) => $counts['total'] === 27 && $counts['active'] === 3 && $counts['completed'] === 1 && $counts['issues'] === 1);
        $this->get('/rider-operations/'.$rider->slug.'?state=completed')->assertViewHas('orders', fn ($orders) => $orders->pluck('slug')->all() === [$completed->slug])
            ->assertSee('return_to=', false)->assertDontSee('rider-order-events');
        $this->getJson('/rider-operations/'.$rider->slug.'?state=invalid')->assertUnprocessable();
    }

    public function test_reassigned_orders_are_not_workload_of_the_previous_rider(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $previous = User::factory()->create();
        $current = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $current->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::OutForDelivery]);
        $order->events()->create(['user_id' => $previous->id, 'rider_id' => $previous->id, 'status' => OrderStatus::PickedUp]);

        $this->actingAs($admin)->get('/rider-operations/'.$previous->slug)->assertViewHas('counts', fn ($counts) => $counts['total'] === 0);
        $this->get('/rider-operations/'.$current->slug)->assertViewHas('counts', fn ($counts) => $counts['total'] === 1);
    }

    public function test_attention_filter_only_includes_open_problems_from_selected_day(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $issue = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::DeliveryIssue]);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::DeliveryIssue]);

        $this->actingAs($admin)->get('/rider-operations/'.$rider->slug.'?state=issues')->assertViewHas('counts', fn ($counts) => $counts['issues'] === 1)
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('slug')->all() === [$issue->slug])->assertSee('Problema nella consegna');
    }

    public function test_official_reschedule_moves_the_delivery_card_and_later_completion_keeps_scheduled_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 08:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::Accepted]);

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug.'/pickup-schedule', ['pickup_date' => '2026-10-09', 'pickup_from' => '10:00', 'pickup_to' => '12:00', 'reason' => 'Nuovo appuntamento concordato', 'version' => 1])->assertOk();
        $this->get('/rider-operations/'.$rider->slug)->assertViewHas('counts', fn ($counts) => $counts['total'] === 0);
        $order->refresh()->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => Carbon::parse('2026-10-10 11:00', 'Europe/Rome')->utc()])->save();
        $this->get('/rider-operations/'.$rider->slug.'?date=2026-10-09')->assertViewHas('counts', fn ($counts) => $counts['total'] === 1 && $counts['completed'] === 1);
        $this->get('/rider-operations/'.$rider->slug.'?date=2026-10-10')->assertViewHas('counts', fn ($counts) => $counts['total'] === 0);
    }

    public function test_accounting_uses_posting_day_and_keeps_recognition_cash_and_open_positions_separate(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $delivered = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::Delivered, 'price_cents' => 1000, 'paid_at' => now()]);
        $unpaid = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08', 'status' => OrderStatus::Delivered, 'price_cents' => 800]);
        $this->entry($delivered, $admin, 1000, '2026-10-08 00:05');
        $this->entry($delivered, $admin, -400, '2026-10-08 10:00', 'reversal', 0);
        $this->entry($delivered, $admin, 0, '2026-10-08 10:01', 'refund', -100);
        $this->entry($delivered, $admin, 99999, '2026-10-07 23:59');
        $this->entry($unpaid, $admin, 200, '2026-10-08 11:00');
        Expense::factory()->create(['user_id' => $rider->id, 'spent_on' => '2026-10-08', 'amount_cents' => 150]);
        Expense::factory()->create(['user_id' => $rider->id, 'spent_on' => '2026-10-07', 'amount_cents' => 99999]);
        PendingAccount::factory()->create(['order_id' => $unpaid->id, 'amount_cents' => 800, 'settled_cents' => 200, 'state' => 'partially_paid', 'occurred_on' => '2026-10-08']);
        $outgoingOrder = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-08']);
        PendingAccount::factory()->create(['order_id' => $outgoingOrder->id, 'direction' => 'outgoing', 'amount_cents' => 300, 'occurred_on' => '2026-10-08']);

        $this->actingAs($admin)->get('/rider-operations/'.$rider->slug)->assertOk()
            ->assertViewHas('accounting', fn ($data) => $data['gross'] === 1200 && $data['reversals'] === 400 && $data['net'] === 800 && $data['balance'] === 650 && $data['cash_change'] === 950 && $data['unrecorded'] === 600 && $data['pending_incoming'] === 600 && $data['pending_outgoing'] === 300)
            ->assertDontSee('Registrazione contabile riservata')->assertSee('La disponibilità fisica richiede');
        $this->get('/rider-operations/'.$rider->slug.'?state=completed')->assertViewHas('accounting', fn ($data) => $data['balance'] === 650 && $data['cash_change'] === 950 && $data['pending_incoming'] === 600);
    }

    public function test_receipt_is_attributed_to_delivery_rider_instead_of_admin_recorder_or_subsequent_assignee(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $deliveryRider = User::factory()->create();
        $laterRider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $laterRider->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::Delivered]);
        $order->events()->create(['user_id' => $admin->id, 'rider_id' => $deliveryRider->id, 'status' => OrderStatus::Delivered]);
        $this->entry($order, $admin, 500, '2026-10-08 09:00');

        $this->actingAs($admin)->get('/rider-operations/'.$deliveryRider->slug)->assertViewHas('accounting', fn ($data) => $data['gross'] === 500);
        $this->get('/rider-operations/'.$laterRider->slug)->assertViewHas('accounting', fn ($data) => $data['gross'] === 0);
        $this->get('/rider-operations/'.$admin->slug)->assertViewHas('accounting', fn ($data) => $data['gross'] === 0);
        $this->get('/rider-operations/'.$deliveryRider->slug.'?date=2026-10-07')->assertViewHas('counts', fn ($counts) => $counts['completed'] === 1);
        $this->get('/rider-operations/'.$laterRider->slug.'?date=2026-10-07')->assertViewHas('counts', fn ($counts) => $counts['completed'] === 0);
    }

    public function test_daily_dashboard_remains_admin_only_and_public_references_are_slugs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->getJson('/rider-operations/'.$rider->slug)->assertUnauthorized();
        $this->actingAs($rider)->getJson('/rider-operations/'.$rider->slug)->assertForbidden();
        $this->actingAs($customer)->getJson('/rider-operations/'.$rider->slug)->assertForbidden();
        $this->actingAs($admin)->get('/rider-operations/'.$rider->id)->assertNotFound();
        $this->get('/rider-operations/'.$customer->slug)->assertNotFound();
        $this->getJson('/rider-operations/'.$rider->slug.'?date=2026-02-30')->assertUnprocessable();
    }
}
