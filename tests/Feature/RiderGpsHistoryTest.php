<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RiderGpsSample;
use App\Models\User;
use App\OrderStatus;
use App\Support\RiderTracking;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiderGpsHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_gps_samples_are_encrypted_throttled_and_visible_only_in_authorized_daily_detail(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 2)->setTime(10, 0));
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery]);
        $tracking = app(RiderTracking::class);
        $session = $tracking->start($order, $rider);
        $send = function (float $latitude = 40.85) use ($tracking, $order, $rider, $session): void {
            $tracking->update($order, $rider, ['session' => $session, 'latitude' => $latitude, 'longitude' => 14.26, 'accuracy' => 12, 'recorded_at' => now()->toIso8601String()]);
        };
        $send();
        $this->travel(10)->seconds();
        $send(40.86);
        $this->assertDatabaseCount('rider_gps_samples', 1);
        $this->travel(50)->seconds();
        $send();
        $this->assertDatabaseCount('rider_gps_samples', 1);
        $this->travel(60)->seconds();
        $send();
        $this->assertDatabaseCount('rider_gps_samples', 2);
        $this->travel(60)->seconds();
        $send(40.86);
        $this->assertDatabaseCount('rider_gps_samples', 3);
        $this->assertSame(40.85, RiderGpsSample::first()->position['latitude']);
        $this->assertStringNotContainsString('latitude', DB::table('rider_gps_samples')->value('position'));
        $this->actingAs($admin)->get('/rider-operations/'.$rider->id.'?date=2026-10-02')->assertOk()->assertViewHas('gpsPoints', fn ($points) => count($points) === 3)->assertSee('3 campioni GPS');
        $this->get('/rider-operations/'.$rider->id.'?date=2026-10-01')->assertOk()->assertViewHas('gpsPoints', []);
        $this->actingAs($rider)->getJson('/rider-operations/'.$rider->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))->getJson('/rider-operations/'.$rider->id)->assertForbidden();
        RiderGpsSample::first()->update(['captured_at' => now()->subDays(31)]);
        $this->artisan('tracking:prune-history')->assertSuccessful();
        $this->assertDatabaseCount('rider_gps_samples', 2);
    }
}
