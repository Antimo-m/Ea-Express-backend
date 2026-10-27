<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RiderGpsSample;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiderGpsHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_removal_preserves_encrypted_historical_samples(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id]);
        $sample = RiderGpsSample::create(['rider_id' => $admin->id, 'order_id' => $order->id, 'position' => ['latitude' => 40.85, 'longitude' => 14.26], 'recorded_at' => now()->subDays(40), 'captured_at' => now()->subDays(40)]);

        $this->actingAs($admin)->get('/dashboard')->assertDontSee('GPS');
        $this->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();

        $order->forceFill(['pickup_point' => ['latitude' => 40.85, 'longitude' => 14.26], 'delivery_point' => ['latitude' => 40.86, 'longitude' => 14.27]])->save();
        $this->assertArrayNotHasKey('pickup_point', $order->fresh()->toArray());
        $this->assertArrayNotHasKey('delivery_point', $order->fresh()->toArray());
        $this->assertModelExists($sample);
        $this->assertSame(40.85, $sample->fresh()->position['latitude']);
        $this->assertStringNotContainsString('latitude', DB::table('rider_gps_samples')->value('position'));
    }
}
