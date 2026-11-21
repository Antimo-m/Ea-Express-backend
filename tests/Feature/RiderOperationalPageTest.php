<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RiderOperationalPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rider;

    private User $otherRider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-21 16:00', 'Europe/Rome'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Mario Rossi']);
        $this->otherRider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Luca Bianchi']);
    }

    private function event(Order $order, OrderStatus $status, string $at, ?int $riderId): void
    {
        $order->events()->make(['status' => $status, 'rider_id' => $riderId, 'user_id' => $this->admin->id])->forceFill(['created_at' => Carbon::parse($at, 'Europe/Rome')])->save();
    }

    private function delivered(User $rider, string $pickup, string $deliveredAt, ?int $price, ?int $eventRider = -1): Order
    {
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'pickup_date' => $pickup, 'price_cents' => $price, 'parcel_value_cents' => 7500, 'delivered_at' => Carbon::parse($deliveredAt, 'Europe/Rome')]);
        $this->event($order, OrderStatus::PickedUp, $pickup.' 10:00', $eventRider === -1 ? $rider->id : $eventRider);
        $this->event($order, OrderStatus::Delivered, $deliveredAt, $eventRider === -1 ? $rider->id : $eventRider);

        return $order;
    }

    public function test_rider_sees_only_the_personal_page_without_accounting_and_cannot_open_other_riders(): void
    {
        $this->delivered($this->rider, '2026-11-21', '2026-11-21 12:00', 500);
        Order::factory()->create(['rider_id' => $this->otherRider->id, 'status' => OrderStatus::InTransit, 'pickup_date' => '2026-11-21', 'price_cents' => 900]);

        $this->actingAs($this->rider)->get(route('rider-operations.mine'))->assertOk()
            ->assertSee('LA MIA PAGINA OPERATIVA')->assertSee('Mario Rossi')->assertDontSee('Luca Bianchi')
            ->assertSee('Tariffe delle spedizioni completate')
            ->assertDontSee('Torna ai Rider')->assertSee('Resoconto contabile odierno di Mario Rossi')->assertSee('Saldo disponibile')->assertDontSee('Sospesi')
            ->assertDontSee('Incassi da registrare')->assertDontSee('Ancora da regolarizzare')->assertDontSee('Saldo contabile')->assertDontSee(route('balance.index'));

        $this->actingAs($this->rider)->get(route('rider-operations.show', $this->otherRider))->assertForbidden();
        $this->actingAs($this->rider)->get(route('rider-operations.show', $this->rider))->assertForbidden();
        $this->actingAs($this->rider)->get(route('rider-operations.mine', ['rider' => $this->otherRider->slug]))->assertOk()->assertDontSee('Luca Bianchi');
        $this->actingAs($this->admin)->get(route('rider-operations.mine'))->assertNotFound();
        $this->actingAs($this->rider)->get('/dashboard')->assertSee(route('rider-operations.mine'), false);
    }

    public function test_rider_cannot_reach_accounting_pages_or_record_receipts_and_pending_items(): void
    {
        $order = $this->delivered($this->rider, '2026-11-21', '2026-11-21 12:00', 500);
        $this->actingAs($this->rider);
        $blocked = fn ($response) => $this->assertContains($response->getStatusCode(), [403, 429], 'Accesso contabile non bloccato.');
        foreach (['/balance', '/movements', '/pending', '/reports', '/economic-audits'] as $page) {
            $blocked($this->get($page));
        }
        $blocked($this->post('/balance/'.$order->slug.'/payment', ['amount' => '5,00']));
        $blocked($this->post('/movements', []));
        $blocked($this->post('/pending', []));
        $blocked($this->post('/expenses', []));
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('pending_accounts', 0);
    }

    public function test_admin_page_keeps_accounting_with_the_new_headings(): void
    {
        $this->actingAs($this->admin)->get(route('rider-operations.show', $this->rider))->assertOk()
            ->assertSee('Torna ai Rider')->assertSee('Resoconto contabile odierno di Mario Rossi')->assertDontSee('SOLO ADMIN')->assertDontSee('Dal 21/11/2026 al 21/11/2026')
            ->assertSee('Saldo disponibile')->assertDontSee('Sospesi in entrata')->assertDontSee('Ancora da regolarizzare');
        $this->get(route('rider-operations.show', [$this->rider, 'period' => 'custom', 'from' => '2026-11-18', 'to' => '2026-11-18']))->assertOk()
            ->assertSee('Resoconto contabile del 18/11/2026 di Mario Rossi')->assertDontSee('odierno');
        $response = $this->get(route('rider-operations.show', [$this->rider, 'period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-21']))->assertOk()
            ->assertSee('Resoconto contabile di Mario Rossi')->assertSee('Dal 01/11/2026 al 21/11/2026');
        $this->assertSame(1, substr_count($response->getContent(), 'rider-accounting-range'));
        $this->get(route('rider-operations.show', [$this->rider, 'period' => 'custom', 'from' => '2026-11-21', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
    }

    public function test_period_statistics_follow_event_dates_tariffs_and_attribution(): void
    {
        // Assegnata con ritiro il 10, consegnata il 12: conta come consegna del 12.
        $this->delivered($this->rider, '2026-11-10', '2026-11-12 11:00', 500);
        // Evento storico senza Rider: attribuito all'assegnatario dell'ordine.
        $this->delivered($this->rider, '2026-11-12', '2026-11-12 15:00', 700, null);
        // Consegnata da un altro Rider anche se oggi assegnata a Mario: non è sua.
        $this->delivered($this->rider, '2026-11-12', '2026-11-12 16:00', 900, $this->otherRider->id);
        // Senza tariffa concordata: conta come consegna ma non nel valore tariffario.
        $this->delivered($this->rider, '2026-11-12', '2026-11-12 17:00', null);
        // Consegna confermata due volte: un solo ordine.
        $twice = $this->delivered($this->rider, '2026-11-12', '2026-11-12 09:00', 300);
        $this->event($twice, OrderStatus::Delivered, '2026-11-12 09:30', $this->rider->id);
        $cancelled = Order::factory()->create(['rider_id' => $this->rider->id, 'status' => OrderStatus::Cancelled, 'pickup_date' => '2026-11-12', 'price_cents' => 400]);
        $this->event($cancelled, OrderStatus::Cancelled, '2026-11-12 13:00', $this->rider->id);
        Order::factory()->create(['rider_id' => $this->rider->id, 'status' => OrderStatus::InTransit, 'pickup_date' => '2026-11-21', 'price_cents' => 650, 'estimated_at' => now()->subHour()]);
        Order::factory()->create(['rider_id' => $this->rider->id, 'status' => OrderStatus::PickupScheduled, 'pickup_date' => '2026-11-22', 'price_cents' => null]);

        $day = $this->actingAs($this->rider)->get(route('rider-operations.mine', ['period' => 'custom', 'from' => '2026-11-12', 'to' => '2026-11-12']))->assertOk();
        $html = $day->getContent();
        $this->assertMatchesRegularExpression('/<dt>Consegnate<\/dt><dd[^>]*>4<\/dd>/', $html);
        $this->assertMatchesRegularExpression('/<dt>Annullate<\/dt><dd[^>]*>1<\/dd>/', $html);
        $day->assertSee('€ 15,00')->assertDontSee('€ 75,00')->assertSee('1 senza tariffa concordata, non conteggiate');
        $day->assertSee('Valore tariffario delle spedizioni in corso')->assertSee('€ 6,50');
        $this->assertMatchesRegularExpression('/rider-now-card--late[^"]*"><dt>.*?In ritardo<\/dt><dd[^>]*>1<\/dd>/s', $html);

        $pickupDay = $this->get(route('rider-operations.mine', ['period' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-10']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dt>Spedizioni assegnate<\/dt><dd[^>]*>1<\/dd>/', $pickupDay);
        $this->assertMatchesRegularExpression('/<dt>Consegnate<\/dt><dd[^>]*>0<\/dd>/', $pickupDay);
        $this->assertMatchesRegularExpression('/<dt>Ritirate<\/dt><dd[^>]*>1<\/dd>/', $pickupDay);

        $year = $this->get(route('rider-operations.mine', ['period' => 'year']))->assertOk()->assertSee('Anno 2026')->assertSee('Totale tariffario per mese')->assertSee('Nov 2026');
        $this->get(route('rider-operations.mine', ['period' => 'year_of', 'year' => 2025]))->assertOk()->assertSee('Anno 2025')->assertSee('€ 0,00');
        $this->get(route('rider-operations.mine', ['period' => 'month_of', 'month' => '2026-11']))->assertOk()->assertSee('Novembre 2026')->assertSee('Totale tariffario per giorno');
        $this->get(route('rider-operations.mine', ['period' => 'custom', 'from' => '2010-01-01', 'to' => '2026-11-21']))->assertSessionHasErrors('to');
    }
}
