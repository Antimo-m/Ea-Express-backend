<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatisticsScalabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_earnings_use_completed_deliveries_and_remain_private(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 27)->setTime(12, 0));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'parcel_value_cents' => 10000, 'price_cents' => 700, 'carrier_cost_cents' => 600, 'shipping_type' => 'external', 'created_at' => '2026-09-10 10:00:00']);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'parcel_value_cents' => 5000, 'price_cents' => 500, 'created_at' => '2026-09-10 11:00:00']);
        foreach ([OrderStatus::Received, OrderStatus::Cancelled, OrderStatus::Rejected] as $status) {
            Order::factory()->create(['customer_id' => $customer->id, 'status' => $status, 'parcel_value_cents' => 99999, 'price_cents' => 9999, 'created_at' => '2026-09-10 10:00:00']);
        }
        Order::factory()->create(['customer_id' => $other->id, 'status' => OrderStatus::Delivered, 'parcel_value_cents' => 999999, 'price_cents' => 9999]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-10&to=2026-09-10&customer_id='.$other->slug)
            ->assertOk()->assertJsonPath('total', 5)->assertJsonPath('delivered', 2)->assertJsonPath('gross_cents', 15000)->assertJsonPath('delivered_spend_cents', 1200)->assertJsonPath('net_cents', 13800)
            ->assertJsonPath('trend.0.net_cents', 13800)->assertJsonPath('missing_values', 0)->assertJsonMissingPath('carrier_cost_cents')->assertJsonMissingPath('prices')->assertJsonMissingPath('detail');
    }

    public function test_missing_merchandise_values_and_negative_net_are_visible(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'parcel_value_cents' => null, 'price_cents' => 500]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics')->assertOk()
            ->assertJsonPath('gross_cents', 0)->assertJsonPath('net_cents', -500)->assertJsonPath('missing_values', 1);
    }

    public function test_daily_aggregates_preserve_rome_midnight_and_daylight_saving_boundaries(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        foreach (['2026-03-28 22:59:59', '2026-03-28 23:00:00', '2026-03-29 00:30:00', '2026-03-29 01:30:00', '2026-03-29 22:00:00'] as $date) {
            Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => $date, 'price_cents' => 500, 'parcel_value_cents' => 1000]);
        }
        foreach (['2026-10-24 22:00:00', '2026-10-25 00:30:00', '2026-10-25 01:30:00', '2026-10-25 22:59:59', '2026-10-25 23:00:00'] as $date) {
            Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => $date, 'price_cents' => 500, 'parcel_value_cents' => 1000]);
        }

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-03-29&to=2026-03-29')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonCount(1, 'trend')->assertJsonPath('trend.0.shipments', 3)->assertJsonPath('trend.0.net_cents', 1500);
        $this->getJson('/api/v1/customer/statistics?period=custom&from=2026-10-25&to=2026-10-25')->assertOk()
            ->assertJsonPath('total', 4)->assertJsonPath('trend.0.shipments', 4)->assertJsonPath('trend.0.net_cents', 2000);
    }

    public function test_sixty_shops_keep_statistics_and_operational_pages_bounded_with_sixty_thousand_orders(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 27)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(60)->create(['role' => UserRole::Customer]);
        foreach ($customers as $customer) {
            for ($batch = 0; $batch < 2; $batch++) {
                $rows = Order::factory()->count(500)->make(['customer_id' => $customer->id, 'created_by' => $admin->id, 'rider_id' => null, 'parcel_value_cents' => 10000, 'price_cents' => 500])->map(function (Order $order, int $index): array {
                    $order->status = $index % 10 === 0 ? OrderStatus::Received : OrderStatus::Delivered;
                    $order->created_at = sprintf('2026-09-%02d %02d:30:00', 1 + $index % 25, $index % 24);
                    $order->delivered_at = $order->status === OrderStatus::Delivered ? $order->created_at : null;
                    $order->updated_at = $order->created_at;

                    return $order->getAttributes();
                })->all();
                Order::insert($rows);
            }
        }
        $measurements = [];
        $this->actingAs($admin, 'web');
        $measure = function (string $name, callable $request) use (&$measurements) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $response = $request()->assertOk();
            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();
            $measurements[$name] = ['milliseconds' => round((hrtime(true) - $start) / 1e6, 1), 'queries' => $queryCount, 'bytes' => strlen($response->getContent())];
            $this->assertLessThan(60, $queryCount, $name.' query count must stay bounded');

            return $response;
        };
        $stores = $measure('staff_statistics', fn () => $this->get('/customers'));
        $stores->assertViewHas('customers', fn ($stores) => $stores->total() === 60 && $stores->count() === 25 && $stores->lastPage() === 3)
            ->assertSee('Clienti');
        $measure('staff_statistics_next', fn () => $this->get('/customers?page=2'))->assertViewHas('customers', fn ($stores) => $stores->count() === 25 && $stores->currentPage() === 2);
        $measure('staff_statistics_detail', fn () => $this->get('/customers/'.$customers->first()->slug))->assertViewHas('orders', fn ($orders) => $orders->total() === 1000 && $orders->count() === 20);
        $measure('staff_dashboard', fn () => $this->get('/dashboard'));
        $measure('staff_orders', fn () => $this->get('/orders/incoming'))->assertViewHas('orders', fn ($orders) => $orders->total() === 6000 && $orders->count() === 20);
        $measure('staff_pickup_calendar', fn () => $this->get('/pickups?month=2026-09'))
            ->assertViewHas('dailyCounts', fn ($counts): bool => $counts->get('2026-09-28') === 6000);
        $measure('staff_pickups', fn () => $this->get('/pickups/day?date=2026-09-28'))
            ->assertViewHas('totalOrders', 6000)
            ->assertViewHas('groups', fn ($groups): bool => $groups->total() === 60 && $groups->count() === 20 && $groups->getCollection()->every(fn ($orders): bool => $orders->count() === 100 && $orders->pluck('customer_id')->unique()->count() === 1))
            ->assertViewHas('orders', fn ($orders): bool => $orders->count() === 2000);
        $measure('staff_reports', fn () => $this->get('/reports'));
        $measure('staff_balance', fn () => $this->get('/balance'));
        foreach ($customers as $index => $customer) {
            $this->actingAs($customer, 'customer');
            $response = $measure('customer_statistics_'.$index, fn () => $this->getJson('/api/v1/customer/statistics'));
            $response->assertJsonPath('total', 1000)->assertJsonPath('delivered', 900)->assertJsonPath('net_cents', 8550000);
            $this->assertLessThan(40000, strlen($response->getContent()));
        }
        $measure('customer_dashboard', fn () => $this->getJson('/api/v1/customer/dashboard'));
        $measure('customer_orders', fn () => $this->getJson('/api/v1/customer/orders'))->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 1000);
        $measure('notification_feed', fn () => $this->getJson('/api/v1/customer/notifications/feed'));
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            file_put_contents($directory.'/scale-sixty-shops.json', json_encode(['shops' => 60, 'orders' => 60000, 'database' => DB::connection()->getDriverName(), 'measurements' => $measurements], JSON_PRETTY_PRINT));
            file_put_contents($directory.'/customers.html', $stores->getContent());
            file_put_contents($directory.'/statistics.json', $response->getContent());
            file_put_contents($directory.'/auth.json', $this->getJson('/api/v1/customer/auth/me')->assertOk()->getContent());
        }
    }
}
