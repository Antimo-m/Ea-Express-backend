<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarrierWorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function forbiddenTransitions(): array
    {
        return ['skipped handover' => [null, 'in_transit'], 'backwards' => ['in_transit', 'booked'], 'repeat handover' => ['in_transit', 'handed_over']];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_carrier_cannot_skip_steps_or_move_backwards(?string $current, string $next): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'shipping_type' => 'external', 'status' => OrderStatus::PickedUp, 'carrier_status' => $current, 'carrier_name' => 'Carrier', 'price_cents' => 600]);
        $order->events()->create(['user_id' => $rider->id, 'status' => OrderStatus::PickedUp]);
        $this->actingAs($rider)->patchJson('/orders/'.$order->slug.'/carrier', ['version' => 1, 'carrier_tracking' => 'TRACK', 'carrier_status' => $next])->assertConflict();
        $this->assertSame($current, $order->fresh()->carrier_status);
        $this->assertSame(1, $order->fresh()->version);
        $this->assertDatabaseCount('economic_audits', 0);
        $this->assertDatabaseCount('order_events', 1);
    }

    public function test_handover_is_atomic_audited_and_cannot_be_replayed(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'shipping_type' => 'external', 'status' => OrderStatus::PickedUp, 'carrier_name' => 'Carrier', 'price_cents' => 600]);
        $order->events()->create(['user_id' => $rider->id, 'status' => OrderStatus::PickedUp]);
        $payload = ['version' => 1, 'carrier_tracking' => 'TRACK', 'carrier_status' => 'handed_over'];
        $this->actingAs($rider)->withHeader('X-Request-ID', 'attacker-chosen')->patchJson('/orders/'.$order->slug.'/carrier', $payload)->assertRedirect();
        $this->patchJson('/orders/'.$order->slug.'/carrier', $payload)->assertConflict();
        $this->assertSame('handed_over', $order->fresh()->carrier_status);
        $this->assertSame(2, $order->fresh()->version);
        $audit = EconomicAudit::sole();
        $this->assertSame($rider->id, $audit->user_id);
        $this->assertSame('carrier.updated', $audit->action);
        $this->assertNotNull($audit->request_id);
        $this->assertNotSame('attacker-chosen', $audit->request_id);
        $this->assertNull($audit->before['carrier_status']);
        $this->assertSame('handed_over', $audit->after['carrier_status']);
    }
}
