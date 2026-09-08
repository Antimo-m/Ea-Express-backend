<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_and_reversal_preserve_audit_and_prevent_double_collection(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1235, 'delivered_at' => now()]);
        $this->actingAs($user)->post(route('payments.store', $order), ['action' => 'receive', 'version' => 1, 'note' => 'Contanti'])->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(1235, PaymentEntry::sole()->amount_cents);
        $this->post(route('payments.store', $order), ['action' => 'receive', 'version' => 1, 'note' => 'Duplicato'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('payment_entries', 1);
        $this->post(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Registrazione errata'])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('payment_entries', 2);
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
    }

    public function test_balance_distinguishes_earned_cash_expenses_and_outstanding(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 8)->setTime(12, 0));
        $user = User::factory()->create();
        $paid = Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::Delivered, 'price_cents' => 2000, 'delivered_at' => now()]);
        Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1500, 'delivered_at' => now()]);
        $other = Order::factory()->create(['rider_id' => User::factory(), 'status' => OrderStatus::Delivered, 'price_cents' => 99999, 'delivered_at' => now()]);
        Expense::factory()->create(['user_id' => $user->id, 'amount_cents' => 500]);
        $this->actingAs($user)->post(route('payments.store', $paid), ['action' => 'receive', 'version' => 1, 'note' => 'Bonifico ricevuto'])->assertSessionHasNoErrors();
        $this->get('/balance')->assertOk()->assertViewHas('earned', 3500)->assertViewHas('cash', 2000)->assertViewHas('spent', 500)->assertViewHas('net', 1500)->assertViewHas('outstanding', 1500)->assertDontSee($other->reference);
    }

    public function test_financial_writes_cannot_target_other_riders_or_unfinished_orders(): void
    {
        $user = User::factory()->create();
        $other = Order::factory()->create(['rider_id' => User::factory(), 'status' => OrderStatus::Delivered, 'price_cents' => 1000]);
        $active = Order::factory()->create(['rider_id' => $user->id, 'status' => OrderStatus::Accepted, 'price_cents' => 1000]);
        $expense = Expense::factory()->create();
        $data = ['action' => 'receive', 'version' => 1, 'note' => 'Test'];
        $this->actingAs($user)->post(route('payments.store', $other), $data)->assertNotFound();
        $this->post(route('payments.store', $active), $data)->assertSessionHasErrors('action');
        $this->delete(route('expenses.destroy', $expense))->assertNotFound();
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertNull($expense->fresh()->voided_at);
    }

    public function test_expense_creation_validates_amount_and_preserves_voided_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/expenses', ['description' => 'Carburante', 'amount' => '0', 'spent_on' => now()->toDateString()])->assertSessionHasErrors('amount');
        $this->post('/expenses', ['description' => 'Carburante', 'amount' => '15,09', 'spent_on' => now()->toDateString(), 'user_id' => 999])->assertSessionHasNoErrors();
        $expense = Expense::sole();
        $this->assertSame(1509, $expense->amount_cents);
        $this->assertSame($user->id, $expense->user_id);
        $this->delete(route('expenses.destroy', $expense))->assertSessionHasNoErrors();
        $this->assertNotNull($expense->fresh()->voided_at);
        $this->get('/balance')->assertViewHas('spent', 0);
    }

    public function test_report_uses_italian_month_boundaries_and_handles_zero_comparison(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['created_at' => '2026-08-31 22:30:00']);
        $this->actingAs($user)->get('/reports?month=2026-09')->assertOk()->assertViewHas('received', 1)->assertViewHas('previous', 0)->assertViewHas('growth', null)->assertViewHas('days', ['01/09' => 1]);
        Order::factory()->create(['created_at' => '2026-08-10 10:00:00']);
        $this->get('/reports?month=2026-09')->assertViewHas('growth', 0.0);
        $this->get('/reports?month=2026-13')->assertSessionHasErrors('month');
        $this->get('/balance?from=2026-09-10&to=2026-09-01')->assertSessionHasErrors('to');
    }
}
