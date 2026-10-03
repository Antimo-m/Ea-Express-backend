<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_rider_totals_include_completed_zones_signed_cash_and_unassigned_orders(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 2)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $active = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_city' => 'Nola', 'delivery_zone' => null, 'price_cents' => 600]);
        $delivered = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'delivery_city' => 'Aversa', 'delivery_zone' => null, 'price_cents' => 500]);
        foreach ([500, -100] as $amount) {
            $payment = new PaymentEntry;
            $payment->forceFill(['order_id' => $delivered->id, 'user_id' => $admin->id, 'amount_cents' => $amount, 'note' => 'Movimento verificato'])->save();
        }
        Order::factory()->count(2)->create(['rider_id' => null, 'status' => OrderStatus::Received, 'pickup_date' => '2026-10-02', 'delivery_city' => 'Nola', 'delivery_zone' => null]);
        $response = $this->actingAs($admin)->getJson('/rider-operations/feed')->assertOk()
            ->assertJsonPath('summary.total', 4)->assertJsonPath('summary.delivered', 1)->assertJsonPath('summary.active', 1)->assertJsonPath('summary.unassigned', 2)->assertJsonPath('summary.riders', 1)->assertJsonPath('summary.zones', 2)
            ->assertJsonPath('unassigned.0.count', 2)->assertJsonMissingPath('orders')->assertJsonMissingPath('gps_session_id');
        $row = collect($response->json('riders'))->firstWhere('id', $rider->id);
        $this->assertSame(2, $row['total_count']);
        $this->assertSame(400, $row['cash_cents']);
        $this->assertSame(1100, $row['expected_cents']);
        $this->assertSame(['Aversa', 'Nola'], collect($row['zones'])->pluck('name')->sort()->values()->all());
        $this->assertContains('Aversa', array_column($response->json('zones'), 'name'));
        $this->get('/rider-operations/'.$rider->id)->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 2);
    }

    public function test_twenty_riders_one_hundred_orders_and_fifteen_zones_keep_queries_bounded(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $riders = User::factory()->count(20)->create();
        for ($index = 0; $index < 100; $index++) {
            Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $riders[$index % 20]->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Zona '.($index % 15), 'price_cents' => 500]);
        }
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->getJson('/rider-operations/feed')->assertOk()->assertJsonCount(20, 'riders')->assertJsonCount(15, 'zones')->assertJsonPath('summary.total', 100)->assertJsonPath('summary.riders', 20);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(20, count($queries));
        $this->assertSame(50000, array_sum(array_column($response->json('riders'), 'expected_cents')));
    }

    public function test_history_keeps_work_in_progress_with_original_rider_and_zone_after_later_delivery(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 2)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $original = User::factory()->create();
        $replacement = User::factory()->create();
        $order = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $replacement->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'delivery_zone' => 'Nola']);
        $order->events()->make(['rider_id' => $original->id, 'operational_zone' => 'Aversa', 'user_id' => $original->id, 'status' => OrderStatus::OutForDelivery])->forceFill(['created_at' => now()->subDay()])->save();
        $response = $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-10-01')->assertOk()->assertJsonPath('summary.active', 1)->assertJsonPath('summary.delivered', 0)->assertJsonPath('summary.total', 1)->assertJsonPath('riders.0.id', $original->id)->assertJsonPath('riders.0.zones.0.name', 'Aversa')->assertJsonPath('zones.0.name', 'Aversa');
        $this->get('/rider-operations/'.$original->id.'?date=2026-10-01')->assertOk()->assertViewHas('remaining', 1)->assertSee('In consegna');
    }

    public function test_period_presets_keep_customer_filter_and_reset_all_independent_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $html = $this->actingAs($admin)->get('/balance?customer_id='.$customer->id.'&cash_page=8&orders_page=9')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<nav class="period-options"[^>]*>(.*?)<\/nav>/s', $html, $matches));
        $this->assertStringContainsString('customer_id='.$customer->id, $matches[1]);
        $this->assertStringNotContainsString('cash_page', $matches[1]);
        $this->assertStringNotContainsString('orders_page', $matches[1]);
        $this->assertStringContainsString('year='.now('Europe/Rome')->year, $matches[1]);
    }

    public function test_pagination_preserves_filters_and_disables_boundary_arrows(): void
    {
        $paginator = new LengthAwarePaginator([], 60, 20, 2, ['path' => '/orders']);
        $paginator->appends(['zone' => 'Aversa']);
        $html = (string) $paginator->links('components.ui.pagination');
        $this->assertStringContainsString('2 / 3', $html);
        $this->assertStringContainsString('zone=Aversa&amp;page=1', $html);
        $this->assertStringContainsString('zone=Aversa&amp;page=3', $html);
        $first = new LengthAwarePaginator([], 60, 20, 1, ['path' => '/orders']);
        $this->assertStringContainsString('disabled aria-label="Pagina precedente"', (string) $first->links('components.ui.pagination'));
        $last = new LengthAwarePaginator([], 60, 20, 3, ['path' => '/orders']);
        $this->assertStringContainsString('disabled aria-label="Pagina successiva"', (string) $last->links('components.ui.pagination'));
    }
}
