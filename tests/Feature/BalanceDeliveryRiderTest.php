<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceDeliveryRiderTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_show_the_rider_recorded_on_the_delivery_without_changing_the_totals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $mario = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Mario Rossi']);
        $luca = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Luca Bianchi']);
        $order = fn (User $rider, ?User $deliveredBy, int $price) => tap(Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => $price, 'parcel_value_cents' => 7500, 'pricing_version' => 1, 'price_state' => 'agreed']),
            fn (Order $order) => $order->events()->create(['status' => OrderStatus::Delivered, 'rider_id' => $deliveredBy?->id, 'user_id' => $admin->id]));
        // Riassegnata dopo la consegna: conta l'evento di consegna, non l'assegnatario attuale.
        $reassigned = $order($luca, $mario, 500);
        $legacy = $order($luca, null, 600);
        $this->actingAs($admin);
        $before = $this->get('/balance')->assertOk();
        $before->assertSee('Consegnata da <b>Mario Rossi</b>', false)->assertDontSee('Consegnata da <b>Luca Bianchi</b>', false)
            ->assertSee('Rider assegnato: Luca Bianchi · consegna non attribuita');

        $this->postJson(route('payments.store', $reassigned), ['action' => 'receive', 'version' => 1])->assertOk();
        $recorded = $this->get('/balance?ledger=payments')->assertOk();
        $recorded->assertSee('Consegnata da <b>Mario Rossi</b>', false)->assertSee('Tariffa spedizione')->assertSee('€ 5,00')->assertDontSee('€ 75,00');
        $this->assertSame(500, (int) $reassigned->payments()->sum('amount_cents'));
        $this->assertSame(0, (int) $legacy->payments()->sum('amount_cents'));
    }
}
