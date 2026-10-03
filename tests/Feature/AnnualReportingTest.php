<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnnualReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_year_applies_rome_boundaries_to_cash_expenses_and_deliveries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 14:00:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach ([['2024-12-31 22:59:59', 99999], ['2024-12-31 23:00:00', 500], ['2025-12-31 22:59:59', 700], ['2025-12-31 23:00:00', 99999]] as [$time, $amount]) {
            $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => Carbon::parse($time, 'UTC'), 'price_cents' => $amount]);
            $payment = new PaymentEntry;
            $payment->order_id = $order->id;
            $payment->user_id = $admin->id;
            $payment->amount_cents = $amount;
            $payment->note = 'Incasso annuale';
            $payment->created_at = Carbon::parse($time, 'UTC');
            $payment->save();
        }
        foreach ([['2025-01-01', 100], ['2025-12-31', 200], ['2026-01-01', 99999]] as [$date, $amount]) {
            $expense = new Expense;
            $expense->user_id = $admin->id;
            $expense->description = 'Spesa annuale';
            $expense->amount_cents = $amount;
            $expense->spent_on = $date;
            $expense->save();
        }
        $this->actingAs($admin)->get('/balance?year=2025&from=2026-10-01&to=2026-10-02')->assertOk()->assertViewHas('cash', 1200)->assertViewHas('earned', 1200)->assertViewHas('spent', 300)->assertViewHas('net', 900)->assertViewHas('completedCount', 2)->assertViewHas('period', fn ($period) => $period->start->toDateString() === '2025-01-01' && $period->end->toDateString() === '2025-12-31');
        $this->getJson('/balance?year=1800')->assertUnprocessable();
    }

    public function test_statistics_supports_year_month_leap_year_and_preserves_customer_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        foreach (['2024-02-29 10:00:00', '2024-12-31 10:00:00', '2025-01-01 10:00:00'] as $date) {
            Order::factory()->create(['customer_id' => $customer->id, 'created_at' => Carbon::parse($date, 'Europe/Rome')->utc(), 'price_cents' => 500]);
        }
        Order::factory()->create(['created_at' => Carbon::parse('2024-05-01', 'UTC'), 'price_cents' => 900]);
        $response = $this->actingAs($admin)->get('/stores?period=year&year=2024&customer_id='.$customer->id)->assertOk()->assertViewHas('summary', fn ($summary) => $summary['total'] === 3)->assertViewHas('detail', fn ($summary) => $summary['total'] === 2)->assertViewHas('trend', fn ($points) => count($points) === 12 && array_sum(array_column($points, 'orders')) === 3)->assertViewHas('previousPeriod', fn ($period) => $period->start->year === 2023 && $period->end->toDateString() === '2023-12-31');
        $response->assertSee('year=2024', false);
        $this->get('/stores?period=month&month=2024-02')->assertOk()->assertViewHas('summary', fn ($summary) => $summary['total'] === 1)->assertViewHas('period', fn ($period) => $period->end->toDateString() === '2024-02-29');
        $this->getJson('/stores?period=year')->assertUnprocessable();
        $this->getJson('/stores?period=month&month=2024-13')->assertUnprocessable();
    }
}
