<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStatisticsDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_deliveries_are_reported_on_completion_with_consistent_totals_and_customer_isolation(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 2)->setTime(18, 0));
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Antimo']);
        $other = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Antimo']);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => '2026-09-21 15:22:43', 'delivered_at' => '2026-10-02 15:41:12', 'parcel_value_cents' => 6000, 'price_cents' => 500]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => '2026-09-20 12:00:00', 'delivered_at' => '2026-10-01 10:00:00', 'parcel_value_cents' => 3000, 'price_cents' => 500]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Received, 'created_at' => '2026-10-02 12:00:00']);
        Order::factory()->create(['customer_id' => $other->id, 'status' => OrderStatus::Delivered, 'created_at' => '2026-10-02 12:00:00', 'delivered_at' => '2026-10-02 15:00:00', 'parcel_value_cents' => 999999]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=today&date_basis=activity&customer_id='.$other->id)
            ->assertOk()->assertJsonPath('date_basis', 'activity')->assertJsonPath('total', 2)->assertJsonPath('delivered', 1)->assertJsonPath('in_progress', 1)
            ->assertJsonPath('gross_cents', 6000)->assertJsonPath('delivered_spend_cents', 500)->assertJsonPath('net_cents', 5500)
            ->assertJsonPath('trend.0.date', '2026-10-02')->assertJsonPath('trend.0.shipments', 2)->assertJsonPath('trend.0.delivered', 1)->assertJsonPath('trend.0.net_cents', 5500)
            ->assertJsonPath('months.0.delivered', 1)->assertJsonPath('months.0.net_cents', 5500)->assertJsonPath('chart_summaries.revenue.total', 5500)
            ->assertJsonPath('comparison.delivered', 1)->assertJsonPath('comparison.net_cents', 2500)->assertJsonPath('changes.net_cents', 120);

        $this->getJson('/api/v1/customer/statistics?period=today')->assertOk()->assertJsonPath('date_basis', 'created_at')->assertJsonPath('delivered', 0);
        $this->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-21&to=2026-09-21&date_basis=created_at')->assertOk()->assertJsonPath('delivered', 1)->assertJsonPath('net_cents', 5500);
        $this->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-21&to=2026-09-21&date_basis=activity')->assertOk()->assertJsonPath('delivered', 0);
        $this->getJson('/api/v1/customer/statistics?date_basis=invalid')->assertUnprocessable()->assertJsonValidationErrors('date_basis');
    }

    public function test_completion_dates_follow_rome_midnight_and_daylight_saving_without_inventing_missing_dates(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        foreach (['2026-10-24 21:59:59', '2026-10-24 22:00:00', '2026-10-25 00:30:00', '2026-10-25 01:30:00', '2026-10-25 22:59:59', '2026-10-25 23:00:00', null] as $completedAt) {
            Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => '2026-10-25 12:00:00', 'delivered_at' => $completedAt, 'parcel_value_cents' => 1000, 'price_cents' => 500]);
        }

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-10-25&to=2026-10-25&date_basis=activity')
            ->assertOk()->assertJsonPath('delivered', 4)->assertJsonCount(1, 'trend')->assertJsonPath('trend.0.delivered', 4)->assertJsonPath('net_cents', 2000);
    }
}
