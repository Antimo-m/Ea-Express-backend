<?php

namespace Tests\Feature;

use App\Models\AccountingControl;
use App\Models\EconomicAudit;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_requests_allow_acceptance_or_rejection_but_not_staff_cancellation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create();
        $this->actingAs($admin)->patchJson(route('orders.update', $order), ['version' => 1, 'status' => 'cancelled', 'note' => 'Motivazione'])->assertUnprocessable();
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->patchJson(route('orders.update', $order), ['version' => 1, 'status' => 'accepted', 'rider_id' => User::factory()->create()->slug, 'price' => '5'])->assertOk();
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $second = Order::factory()->create();
        $this->patchJson(route('orders.update', $second), ['version' => 1, 'status' => 'rejected', 'note' => 'Fuori zona'])->assertOk();
        $this->assertSame(OrderStatus::Rejected, $second->fresh()->status);
        $this->assertDatabaseHas('order_events', ['order_id' => $second->id, 'status' => 'rejected', 'note' => 'Fuori zona']);
    }

    public function test_expense_timeline_preserves_changes_after_new_authentication_and_escapes_notes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->postJson('/expenses', ['description' => 'Carburante', 'amount' => '150', 'spent_on' => now('Europe/Rome')->toDateString()])->assertCreated();
        $expense = Expense::sole();
        $this->patchJson(route('expenses.update', $expense), ['description' => '<script>alert(1)</script>', 'amount' => '170', 'spent_on' => $expense->spent_on->toDateString(), 'version' => 1])->assertOk();
        $this->delete(route('expenses.destroy', $expense))->assertRedirect();
        $this->assertDatabaseCount('economic_audits', 3);
        $change = EconomicAudit::where('action', 'expense.updated')->sole();
        $this->assertSame(15000, $change->before['amount_cents']);
        $this->assertSame(17000, $change->after['amount_cents']);
        auth()->logout();
        $page = $this->actingAs($admin->fresh())->get('/economic-audits?type=expenses&slug='.$expense->slug)->assertOk()->assertSee('Spesa registrata')->assertSee('Spesa corretta')->assertSee('Spesa annullata')->assertSee('150,00')->assertSee('170,00')->assertSee($admin->name)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('amount_cents');
        if (getenv('EA_UI_CAPTURE')) {
            file_put_contents('/private/tmp/ea-ui-final/audit.html', $page->getContent());
        }
        $this->actingAs(User::factory()->create())->get('/economic-audits?type=expenses&slug='.$expense->slug)->assertForbidden();
    }

    public function test_closed_period_blocks_backdated_creation_modification_and_deletion_until_audited_reopening(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create(['user_id' => $admin->id, 'spent_on' => '2026-09-21', 'amount_cents' => 15000]);
        $this->actingAs($admin)->patch('/settings/accounting', ['action' => 'close', 'closed_through' => '2026-09-22', 'version' => 1, 'reason' => 'Conti verificati'])->assertRedirect();
        $data = ['description' => 'Rettifica', 'amount' => '170', 'spent_on' => '2026-09-21', 'version' => 1];
        $this->postJson('/expenses', $data)->assertConflict();
        $this->patchJson(route('expenses.update', $expense), [...$data, 'spent_on' => '2026-09-23'])->assertConflict();
        $this->deleteJson(route('expenses.destroy', $expense))->assertConflict();
        $this->assertSame(15000, $expense->fresh()->amount_cents);
        $this->assertNull($expense->fresh()->voided_at);
        $this->patchJson('/settings/accounting', ['action' => 'reopen', 'version' => 2])->assertUnprocessable();
        $this->patchJson('/settings/accounting', ['action' => 'reopen', 'version' => 1, 'reason' => 'Rettifica'])->assertConflict();
        $this->patch('/settings/accounting', ['action' => 'reopen', 'version' => 2, 'reason' => 'Rettifica autorizzata'])->assertRedirect();
        $this->patchJson(route('expenses.update', $expense), $data)->assertOk();
        $this->assertSame(17000, $expense->fresh()->amount_cents);
        $this->assertNull(AccountingControl::find(1)->closed_through);
        $this->assertDatabaseHas('economic_audits', ['action' => 'accounting.reopened', 'user_id' => $admin->id]);
        $this->actingAs(User::factory()->create())->patchJson('/settings/accounting', ['action' => 'close', 'version' => 3, 'closed_through' => '2026-09-22', 'reason' => 'Tentativo'])->assertForbidden();
    }

    public function test_settlement_audit_is_persistent_and_original_closed_date_prevents_rewriting_cash(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'price_cents' => 20000, 'delivered_at' => now()]);
        $account = PendingAccount::factory()->create(['created_by' => $admin->id, 'order_id' => $order->id, 'amount_cents' => 20000, 'direction' => 'incoming']);
        $this->actingAs($admin)->postJson(route('pending.settle', $account), ['version' => 1, 'amount' => '80', 'submission_key' => (string) Str::uuid()])->assertOk();
        $payment = PendingSettlement::sole();
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'pending_settlements', 'entity_id' => $payment->id, 'action' => 'settlement.created']);
        $this->get('/economic-audits?type=orders&slug='.$order->slug)->assertSee('Incasso registrato')->assertSee('80,00');
        $this->travelTo(now()->setDate(2026, 9, 23));
        $this->patch('/settings/accounting', ['action' => 'close', 'closed_through' => '2026-09-22', 'version' => 1, 'reason' => 'Conti verificati'])->assertRedirect();
        $this->patchJson(route('pending.settlements.update', [$account, $payment]), ['version' => 2, 'amount' => '100', 'reason' => 'Rettifica'])->assertConflict();
        $this->assertSame(8000, PaymentEntry::sole()->amount_cents);
        $this->assertSame(8000, $account->fresh()->settled_cents);
    }

    public function test_rescheduling_records_original_and_new_slot_without_accepting_received_request(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-09-24', 'pickup_reminded_on' => '2026-09-23']);
        $data = ['version' => 1, 'pickup_date' => '2026-09-25', 'pickup_from' => '10:00', 'pickup_to' => '13:00', 'reason' => 'Orario richiesto dal cliente'];
        $this->actingAs($admin)->patch(route('orders.pickup-schedule', $order), $data)->assertRedirect(route('orders.show', $order));
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->assertNull($order->fresh()->pickup_reminded_on);
        $event = $order->events()->sole();
        $this->assertSame('2026-09-24', $event->schedule_change['before']['pickup_date']);
        $this->assertSame('2026-09-25', $event->schedule_change['after']['pickup_date']);
        $this->assertSame($admin->id, $event->user_id);
        $this->patchJson(route('orders.pickup-schedule', $order), $data)->assertConflict();
        $this->patchJson(route('orders.pickup-schedule', $order), [...$data, 'version' => 2])->assertUnprocessable();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()->assertJsonPath('data.events.0.schedule_change.after.pickup_date', '2026-09-25');
        $this->assertDatabaseCount('order_events', 1);
        $page = $this->actingAs($admin, 'web')->get(route('orders.show', $order))->assertOk()->assertSee('24/09/2026')->assertSee('25/09/2026');
        if (getenv('EA_UI_CAPTURE')) {
            file_put_contents('/private/tmp/ea-ui-final/order.html', $page->getContent());
            file_put_contents('/private/tmp/ea-ui-final/settings.html', $this->get('/settings')->assertOk()->getContent());
        }
    }

    public function test_rescheduling_cannot_modify_collected_closed_or_invisible_orders(): void
    {
        $this->freezeTime();
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::DeliveryIssue]);
        $order->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $rider->id]);
        $data = ['version' => 1, 'pickup_date' => now()->addDays(3)->toDateString(), 'pickup_from' => '10:00', 'pickup_to' => '13:00', 'reason' => 'Spostamento'];
        $this->actingAs($rider)->patchJson(route('orders.pickup-schedule', $order), $data)->assertConflict();
        $closed = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Rejected]);
        $this->patchJson(route('orders.pickup-schedule', $closed), $data)->assertNotFound();
        $other = Order::factory()->create(['rider_id' => User::factory()->create()->id, 'status' => OrderStatus::Accepted]);
        $this->patchJson(route('orders.pickup-schedule', $other), $data)->assertNotFound();
        $this->assertDatabaseCount('order_events', 1);
    }

    public function test_pickup_reminders_persist_once_per_rome_day_and_ignore_collected_or_future_orders(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $today = Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-09-23']);
        $old = Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-09-22']);
        $future = Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-09-25']);
        $collected = Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-09-22', 'status' => OrderStatus::Rescheduled]);
        $collected->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $admin->id]);
        $this->artisan('orders:remind-pickups')->assertSuccessful();
        $count = $admin->notifications()->count();
        $this->assertSame(2, $count);
        $this->artisan('orders:remind-pickups')->assertSuccessful();
        $this->assertSame($count, $admin->notifications()->count());
        $this->assertSame('2026-09-23', $today->fresh()->pickup_reminded_on->toDateString());
        $this->assertNotNull($old->fresh()->pickup_reminded_on);
        $this->assertNull($future->fresh()->pickup_reminded_on);
        $this->assertNull($collected->fresh()->pickup_reminded_on);
        $this->travelTo(now()->addDay());
        $this->artisan('orders:remind-pickups')->assertSuccessful();
        $this->assertSame(4, $admin->notifications()->count());
    }

    public function test_agenda_uses_rome_date_and_keeps_each_calendar_day_separate(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22 22:30:00', 'UTC'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $today = Order::factory()->create(['pickup_date' => '2026-09-23']);
        $old = Order::factory()->create(['pickup_date' => '2026-09-22']);
        $this->actingAs($admin)->get(route('pickups.index'))->assertOk()->assertSee('Settembre 2026');
        $page = $this->get(route('pickups.day', ['date' => '2026-09-23']))->assertViewMissing('orders')->assertDontSee($today->reference);
        foreach (['2026-09-23' => $today, '2026-09-22' => $old] as $date => $order) {
            $summary = $this->getJson(route('pickups.day', ['date' => $date]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total_orders', 1);
            $this->getJson($summary->json('data.0.orders_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $order->slug);
        }
        if (getenv('EA_UI_CAPTURE')) {
            file_put_contents('/private/tmp/ea-ui-final/agenda.html', $page->getContent());
        }
    }
}
