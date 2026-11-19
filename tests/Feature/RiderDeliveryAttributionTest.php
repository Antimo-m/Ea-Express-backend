<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Attribuzione storica delle consegne: vale l'ultimo evento di consegna (data, poi id); gli eventi di ritardo non contano;
 * senza Rider sull'evento si usa l'assegnatario dell'ordine.
 */
class RiderDeliveryAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_latest_delivery_event_decides_rider_zone_counts_and_receipts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$first, $second, $third, $assignee] = User::factory()->count(4)->create()->all();
        $delivered = ['status' => OrderStatus::Delivered, 'pickup_date' => '2026-10-07', 'delivery_city' => 'Caserta', 'price_cents' => 1000];

        $redelivered = Order::factory()->create([...$delivered, 'rider_id' => $assignee->id]);
        $this->delivery($redelivered, $admin, $first, '2026-10-07 10:00:00', 'Zona Nord');
        $this->delivery($redelivered, $admin, $second, '2026-10-07 11:00:00', 'Zona Sud');
        $this->delivery($redelivered, $admin, $third, '2026-10-07 12:00:00', 'Zona Ritardi', delay: true);

        $tied = Order::factory()->create([...$delivered, 'rider_id' => $assignee->id]);
        $this->delivery($tied, $admin, $first, '2026-10-07 15:00:00', 'Zona Nord');
        $this->delivery($tied, $admin, $third, '2026-10-07 15:00:00', 'Zona Est');

        $legacy = Order::factory()->create([...$delivered, 'rider_id' => $assignee->id]);
        $this->delivery($legacy, $admin, null, '2026-10-07 16:00:00', null);

        foreach ([$redelivered, $tied, $legacy] as $order) {
            $this->receipt($order, $admin);
        }
        $this->actingAs($admin);

        $expected = [$first->slug => 0, $second->slug => 1, $third->slug => 1, $assignee->slug => 1];
        foreach ($expected as $slug => $completed) {
            $this->get('/rider-operations/'.$slug.'?date=2026-10-07')->assertOk()
                ->assertViewHas('counts', fn (array $counts): bool => $counts['completed'] === $completed)
                ->assertViewHas('accounting', fn (array $accounting): bool => $accounting['gross'] === $completed * 1000);
        }

        $day = $this->getJson('/rider-operations/feed?date=2026-10-07')->assertOk()->json();
        $zones = collect($day['zones'])->pluck('delivered', 'name')->all();
        $this->assertSame(['Caserta' => 1, 'Zona Est' => 1, 'Zona Sud' => 1], collect($zones)->sortKeys()->all());
        $this->assertSame(3, $day['summary']['delivered']);
        $this->assertSame(3000, collect($day['zones'])->sum('cash_cents'));
    }

    private function delivery(Order $order, User $admin, ?User $rider, string $at, ?string $zone, bool $delay = false): void
    {
        $event = $order->events()->create(['user_id' => $admin->id, 'rider_id' => $rider?->id, 'status' => OrderStatus::Delivered, 'operational_zone' => $zone]);
        $event->forceFill(['created_at' => Carbon::parse($at, 'Europe/Rome')->utc(), 'delay_action' => $delay ? 'reported' : null])->save();
    }

    private function receipt(Order $order, User $admin): void
    {
        $entry = new PaymentEntry;
        $entry->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'amount_cents' => 1000, 'kind' => 'receipt', 'note' => '', 'created_at' => Carbon::parse('2026-10-07 18:00', 'Europe/Rome')->utc()])->save();
    }
}
