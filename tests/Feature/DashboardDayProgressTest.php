<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardDayProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_dashboard_shows_today_progress_with_the_rider_operations_counts(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 17:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $other = User::factory()->create(['role' => UserRole::Rider]);
        Order::factory()->count(3)->create(['pickup_date' => '2026-11-21', 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        Order::factory()->create(['pickup_date' => '2026-11-21', 'rider_id' => $other->id, 'status' => OrderStatus::InTransit]);
        Order::factory()->create(['pickup_date' => '2026-11-21', 'status' => OrderStatus::Cancelled]);
        Order::factory()->create(['pickup_date' => '2026-11-20', 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['data-live-region="day-progress"', 'Oggi <strong>3</strong> consegne completate', '75<small>%</small>', 'In programma oggi', '4'], false)
            ->assertSee('style="--value: 75%"', false);

        $this->actingAs($rider)->get('/dashboard')->assertOk()
            ->assertSee('LA TUA GIORNATA')->assertSee('Oggi <strong>3</strong> consegne completate', false)->assertSee('style="--value: 100%"', false);
    }

    public function test_empty_day_shows_no_progress_without_dividing_by_zero(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/dashboard')->assertOk()
            ->assertSee('Oggi <strong>0</strong> consegne completate', false)->assertSee('Nessun ritiro in programma')->assertSee('style="--value: 0%"', false);
    }
}
