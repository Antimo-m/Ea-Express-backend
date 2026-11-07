<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaunchScalabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_feed_keeps_its_payload_and_query_count_bounded_with_many_active_orders(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 5)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $count = max(6000, min(100000, (int) (getenv('EA_STRESS_ORDERS') ?: 6000)));
        for ($inserted = 0; $inserted < $count; $inserted += 500) {
            $rows = Order::factory()->count(min(500, $count - $inserted))->make([
                'created_by' => $admin->id, 'rider_id' => $rider->id,
                'pickup_date' => '2026-11-05', 'delivery_zone' => 'Caserta',
                'status' => OrderStatus::OutForDelivery, 'price_cents' => 500,
                'created_at' => now(), 'updated_at' => now(),
            ])->map(fn (Order $order): array => $order->getAttributes())->all();
            Order::insert($rows);
        }
        unset($rows);
        $this->actingAs($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $memory = memory_get_usage(true);

        $response = $this->getJson('/rider-operations/feed?date=2026-11-05');

        $measurements = [
            'orders' => $count, 'database' => DB::connection()->getDriverName(),
            'milliseconds' => round((hrtime(true) - $start) / 1e6, 1),
            'queries' => count(DB::getQueryLog()), 'bytes' => strlen($response->getContent()),
            'memory_growth_bytes' => memory_get_usage(true) - $memory,
            'process_peak_memory_bytes' => memory_get_peak_usage(true),
        ];
        DB::disableQueryLog();
        $response->assertOk()->assertJsonPath('summary.total', $count)
            ->assertJsonPath('summary.active', $count)->assertJsonPath('summary.riders', 1)
            ->assertJsonCount(1, 'zones')->assertJsonCount(1, 'riders')
            ->assertJsonPath('zones.0.total', $count)->assertJsonPath('zones.0.expected_cents', $count * 500)
            ->assertJsonMissingPath('orders')->assertJsonMissingPath('gps_session_id');
        $this->assertLessThan($count / 20, $measurements['queries']);
        $this->assertLessThan(10000, $measurements['bytes']);
        $this->assertLessThan(32 * 1024 * 1024, $measurements['memory_growth_bytes']);
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            file_put_contents($directory.'/operational-feed-scale.json', json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_chunked_groups_keep_programmed_counts_distinct_from_orders_outside_the_period(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 5)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $other = User::factory()->create();
        Order::insert(Order::factory()->count(1501)->make([
            'created_by' => $admin->id, 'rider_id' => $rider->id,
            'pickup_date' => '2026-11-05', 'delivery_zone' => 'Caserta',
            'status' => OrderStatus::Delivered, 'delivered_at' => now()->subHour(),
            'price_cents' => 500, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ])->map(fn (Order $order): array => $order->getAttributes())->all());
        Order::factory()->create([
            'created_by' => $admin->id, 'rider_id' => $other->id,
            'pickup_date' => '2026-11-05', 'delivery_zone' => 'Napoli',
            'status' => OrderStatus::OutForDelivery, 'price_cents' => 700,
            'created_at' => now()->subDays(2),
        ]);
        foreach ([['2026-11-04', $other->id, 'Caserta'], ['2026-11-06', null, 'Napoli']] as [$date, $riderId, $zone]) {
            Order::factory()->create([
                'created_by' => $admin->id, 'rider_id' => $riderId,
                'pickup_date' => $date, 'delivery_zone' => $zone,
                'status' => OrderStatus::OutForDelivery, 'price_cents' => 9900,
                'created_at' => now()->subDays(2),
            ]);
        }
        Order::factory()->create([
            'created_by' => $admin->id, 'rider_id' => $rider->id,
            'pickup_date' => '2026-11-04', 'delivery_zone' => 'Caserta',
            'status' => OrderStatus::Cancelled,
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-11-05');

        $response->assertOk()->assertJsonPath('summary.total', 1502)
            ->assertJsonPath('summary.delivered', 1501)->assertJsonPath('summary.active', 1)
            ->assertJsonMissingPath('summary.overdue')->assertJsonMissingPath('summary.backlog')->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.riders', 2)->assertJsonPath('summary.unassigned', 0);
        $zones = collect($response->json('zones'))->keyBy('name');
        $this->assertSame(1501, $zones['Caserta']['total']);
        $this->assertSame(750500, $zones['Caserta']['expected_cents']);
        $this->assertSame(0, $zones['Caserta']['pending']);
        $this->assertSame(1, $zones['Napoli']['total']);
        $this->assertSame(700, $zones['Napoli']['expected_cents']);
        $this->assertSame(1, $zones['Napoli']['pending']);
        $unassigned = collect($response->json('riders'))->firstWhere('key', 'unassigned');
        $this->assertNull($unassigned);
    }

    public function test_an_order_rescheduled_during_aggregation_is_counted_once_in_the_current_snapshot(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 5)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create([
            'created_by' => $admin->id, 'pickup_date' => '2026-11-05',
            'delivery_zone' => 'Caserta', 'status' => OrderStatus::OutForDelivery,
            'price_cents' => 500,
            'created_at' => now()->subDays(2),
        ]);
        $rescheduled = false;
        DB::listen(function (QueryExecuted $query) use ($order, &$rescheduled): void {
            $sql = str_replace(['"', '`'], '', $query->sql);
            if (! $rescheduled && str_contains($sql, 'from orders') && str_contains($sql, 'pickup_date >=') && str_contains($sql, 'pickup_date <') && str_contains($sql, 'limit ')) {
                $rescheduled = true;
                DB::table('orders')->where('id', $order->id)->update(['pickup_date' => '2026-11-04']);
            }
        });

        $response = $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-11-05');

        $this->assertTrue($rescheduled);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'pickup_date' => '2026-11-04']);
        $response->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.pending', 0)->assertJsonPath('summary.unassigned', 1)
            ->assertJsonPath('zones.0.total', 1)->assertJsonPath('zones.0.unassigned', 1)
            ->assertJsonPath('zones.0.expected_cents', 500);
    }
}
