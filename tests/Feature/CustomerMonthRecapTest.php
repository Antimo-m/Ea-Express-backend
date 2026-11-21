<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerMonthRecapTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_month_recap_matches_the_statistics_of_the_same_month_and_only_counts_own_orders(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 10:00', 'Europe/Rome'));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(2)->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::InTransit]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => Carbon::parse('2026-10-15 12:00', 'Europe/Rome')]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => Carbon::parse('2026-05-31 12:00', 'Europe/Rome')]);
        Order::factory()->count(4)->create(['customer_id' => User::factory()->state(['role' => UserRole::Customer]), 'status' => OrderStatus::Delivered]);

        $recap = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/dashboard')->assertOk()->json('month_recap');

        $this->assertSame('2026-11', $recap['month']);
        $this->assertSame(3, $recap['shipments']);
        $this->assertSame(2, $recap['delivered']);
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($recap['months'], 'month'));
        $this->assertSame([0, 0, 0, 0, 1, 3], array_column($recap['months'], 'shipments'));

        $statistics = $this->getJson('/api/v1/customer/statistics')->assertOk();
        $this->assertSame($statistics->json('total'), $recap['shipments']);
        $this->assertSame($statistics->json('delivered'), $recap['delivered']);
    }
}
