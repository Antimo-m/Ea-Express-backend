<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RescheduledPickupHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_lists_the_latest_twenty_pickups_moved_from_the_selected_day_only(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Accepted, 'pickup_date' => '2026-10-09']);
        $move = fn (?string $from, string $to) => $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Accepted,
            'schedule_change' => ['before' => $from ? ['pickup_date' => $from, 'pickup_from' => '09:00', 'pickup_to' => '12:00'] : null, 'after' => ['pickup_date' => $to, 'pickup_from' => '09:00', 'pickup_to' => '12:00']]]);
        $move(null, '2026-10-07');
        $move('2026-10-06', '2026-10-07');
        $move('2026-10-08', '2026-10-09');
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Accepted]);
        $moved = collect(range(1, 22))->map(fn (int $index) => $move('2026-10-07', '2026-10-'.(10 + $index % 5)));

        $history = $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-10-07')->assertOk()->json('schedule_history');

        $this->assertCount(20, $history);
        $this->assertSame(array_fill(0, 20, '2026-10-07'), array_column(array_column($history, 'before'), 'pickup_date'));
        $this->assertSame($moved->reverse()->take(20)->map(fn ($event) => $event->schedule_change['after']['pickup_date'])->values()->all(), array_column(array_column($history, 'after'), 'pickup_date'));
        $this->assertSame(['2026-10-09'], array_column(array_column($this->getJson('/rider-operations/feed?date=2026-10-08')->json('schedule_history'), 'after'), 'pickup_date'));
    }
}
