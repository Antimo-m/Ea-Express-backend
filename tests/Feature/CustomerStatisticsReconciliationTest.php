<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStatisticsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_peak_of_118_is_consistent_with_period_net_of_231_50(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        foreach ([['2026-09-16', 1200, 200], ['2026-09-16', 4000, 150], ['2026-09-16', 5000, 500], ['2026-09-16', 4000, 2000], ['2026-09-25', 6000, 500], ['2026-09-25', 7000, 700]] as [$date, $value, $price]) {
            Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => $date.' 12:00:00', 'delivered_at' => $date.' 13:00:00', 'price_cents' => $price, 'quoted_price_cents' => 99999, 'parcel_value_cents' => $value]);
        }
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::InTransit, 'created_at' => '2026-09-21 12:00:00', 'price_cents' => 500, 'parcel_value_cents' => 99999]);

        $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-01&to=2026-09-27')->assertOk()
            ->assertJsonPath('total', 7)->assertJsonPath('delivered', 6)->assertJsonPath('gross_cents', 27200)->assertJsonPath('delivered_spend_cents', 4050)->assertJsonPath('net_cents', 23150)
            ->assertJsonPath('chart_summaries.revenue.total', 23150)->assertJsonPath('chart_summaries.revenue.peak.date', '2026-09-25')->assertJsonPath('chart_summaries.revenue.peak.value', 11800)
            ->assertJsonPath('trend.15.net_cents', 11350)->assertJsonPath('trend.24.net_cents', 11800)->assertJsonPath('trend.24.delivered', 2)->assertJsonPath('date_basis', 'created_at');
        $data = $response->json();
        $this->assertSame($data['total'], array_sum(array_column($data['trend'], 'shipments')));
        $this->assertSame($data['delivered'], array_sum(array_column($data['trend'], 'delivered')));
        $this->assertSame($data['net_cents'], array_sum(array_column($data['trend'], 'net_cents')));
        $this->assertSame($data['gross_cents'], array_sum(array_column($data['trend'], 'gross_cents')));
        $this->assertSame($data['total'], array_sum(array_column($data['months'], 'shipments')));
        $this->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-25&to=2026-09-25')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('net_cents', 11800)->assertJsonCount(1, 'trend')->assertJsonPath('months.0.shipments', 2);
    }

    public function test_monthly_chart_follows_the_selected_period_and_preserves_tenant_scope(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        foreach (['2026-01-01 12:00:00', '2026-01-15 12:00:00', '2026-02-01 12:00:00', '2026-02-15 12:00:00'] as $date) {
            Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => $date, 'delivered_at' => '2026-09-01 12:00:00', 'parcel_value_cents' => 12345, 'price_cents' => 555]);
        }
        Order::factory()->create(['customer_id' => $other->id, 'created_at' => '2026-01-15 12:00:00']);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-10 12:00:00']);

        $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-01-15&to=2026-02-01&customer_id='.$other->slug)->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('net_cents', 23580)->assertJsonCount(2, 'months')->assertJsonPath('months.0.month', '2026-01')->assertJsonPath('months.0.shipments', 1)->assertJsonPath('months.1.shipments', 1)
            ->assertJsonMissingPath('carrier_cost_cents')->assertJsonMissingPath('prices');
        $data = $response->json();
        $this->assertSame($data['net_cents'], array_sum(array_column($data['months'], 'net_cents')));
        $this->assertSame($data['total'], array_sum(array_column($data['trend'], 'shipments')));
    }

    public function test_empty_period_has_zero_totals_and_no_invented_best_day(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-01&to=2026-09-03')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('net_cents', 0)->assertJsonPath('chart_summaries.orders.peak', null)->assertJsonPath('chart_summaries.revenue.peak', null)
            ->assertJsonCount(3, 'trend')->assertJsonPath('months.0.shipments', 0)->assertJsonPath('comparison.total', 0);
    }

    public function test_negative_net_zero_net_and_missing_values_do_not_disappear(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-10 12:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 500, 'parcel_value_cents' => 200, 'shipping_type' => 'external', 'carrier_cost_cents' => 400]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-11 12:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 500, 'parcel_value_cents' => 500]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-12 12:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => null, 'parcel_value_cents' => null]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-10&to=2026-09-12')->assertOk()
            ->assertJsonPath('net_cents', -300)->assertJsonPath('trend.0.net_cents', -300)->assertJsonPath('trend.1.net_cents', 0)->assertJsonPath('external_count', 1)
            ->assertJsonPath('missing_prices', 1)->assertJsonPath('missing_values', 1)->assertJsonPath('chart_summaries.revenue.peak.value', 0);
    }

    public function test_comparison_uses_the_same_completed_delivery_metric(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-09 12:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 555, 'parcel_value_cents' => 12345]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-10 12:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 1110, 'parcel_value_cents' => 24690]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-10&to=2026-09-10')->assertOk()
            ->assertJsonPath('net_cents', 23580)->assertJsonPath('comparison.net_cents', 11790)->assertJsonPath('changes.net_cents', 100)->assertJsonPath('previous_from', '2026-09-09');
    }
}
