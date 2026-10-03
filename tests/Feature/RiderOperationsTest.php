<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\Support\RiderTracking;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-02 14:00:00', 'Europe/Rome'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function delivery(User $rider, string $zone, string $time = '2026-10-01 10:30:00'): Order
    {
        $at = Carbon::parse($time, 'Europe/Rome')->utc();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'delivery_zone' => $zone, 'status' => OrderStatus::Delivered, 'delivered_at' => $at, 'assigned_at' => $at->copy()->subHour(), 'price_cents' => 500, 'parcel_value_cents' => 100000]);
        $order->events()->make(['rider_id' => $rider->id, 'operational_zone' => $zone, 'user_id' => $rider->id, 'status' => OrderStatus::PickedUp])->forceFill(['created_at' => $at->copy()->subMinutes(30)])->save();
        $order->events()->make(['rider_id' => $rider->id, 'operational_zone' => $zone, 'user_id' => $rider->id, 'status' => OrderStatus::Delivered])->forceFill(['created_at' => $at])->save();

        return $order;
    }

    private function payment(Order $order, User $operator, int $amount, ?int $retained = null): void
    {
        $payment = new PaymentEntry;
        $payment->order_id = $order->id;
        $payment->user_id = $operator->id;
        $payment->amount_cents = $amount;
        $payment->ea_amount_cents = $retained;
        $payment->note = $amount < 0 ? 'Storno o rettifica' : 'Incasso registrato';
        $payment->save();
    }

    public function test_global_pages_and_feed_are_admin_only(): void
    {
        $rider = User::factory()->create();
        foreach (['/rider-operations', '/rider-operations/feed', '/rider-operations/'.$rider->id] as $path) {
            $this->getJson($path)->assertUnauthorized();
            $this->actingAs($rider)->getJson($path)->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))->getJson($path)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
        $this->actingAs($this->admin())->getJson('/rider-operations/feed?date=2026-10-03')->assertUnprocessable();
        $this->getJson('/rider-operations/feed?date=wrong')->assertUnprocessable();
    }

    public function test_today_uses_dynamic_zones_and_fresh_real_gps_for_three_riders(): void
    {
        $admin = $this->admin();
        $sara = User::factory()->create(['name' => 'Sara']);
        $marco = User::factory()->create(['name' => 'Marco']);
        $offline = User::factory()->create(['name' => 'Rider offline', 'is_active' => false]);
        $orders = collect();
        foreach ([$sara, $sara, $marco, $offline] as $index => $rider) {
            $orders->push(Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => $index < 2 ? 'Zona nuova Pozzuoli' : 'Area Salerno']));
        }
        $order = $orders->first();
        $session = $this->actingAs($sara)->postJson('/orders/'.$order->id.'/location/session')->assertOk()->json('session');
        $this->putJson('/orders/'.$order->id.'/location', ['session' => $session, 'latitude' => 40.8518, 'longitude' => 14.2681, 'accuracy' => 12, 'recorded_at' => now()->toIso8601String()])->assertOk();
        $feed = $this->actingAs($admin)->getJson('/rider-operations/feed')->assertOk()->assertJsonPath('today', true)->assertJsonCount(1, 'locations');
        $zones = collect($feed->json('zones'))->keyBy('name');
        $this->assertSame(2, $zones['Zona nuova Pozzuoli']['riders'][0]['active_count']);
        $this->assertSame('live', $zones['Zona nuova Pozzuoli']['riders'][0]['state']);
        $this->assertContains('offline', array_column($zones['Area Salerno']['riders'], 'state'));
        $this->assertSame('Sara', $feed->json('locations.0.rider_name'));
        $this->travel(91)->seconds();
        $feed = $this->getJson('/rider-operations/feed')->assertOk()->assertJsonCount(0, 'locations');
        $saraRow = collect($feed->json('zones'))->firstWhere('name', 'Zona nuova Pozzuoli')['riders'][0];
        $this->assertSame('stale', $saraRow['state']);
        $this->assertNotNull($saraRow['last_gps']);
    }

    public function test_history_places_rider_in_every_delivered_zone_and_ignores_current_zone_changes(): void
    {
        $rider = User::factory()->create(['name' => 'Sara']);
        $other = User::factory()->create();
        $first = $this->delivery($rider, 'Aversa');
        $this->delivery($rider, 'Aversa', '2026-10-01 11:30:00');
        $this->delivery($rider, 'Napoli', '2026-10-01 12:30:00');
        $this->delivery($other, 'Aversa', '2026-10-01 13:30:00');
        $first->forceFill(['delivery_zone' => 'Zona cambiata'])->save();
        $feed = $this->actingAs($this->admin())->getJson('/rider-operations/feed?date=2026-10-01')->assertOk()->assertJsonPath('today', false)->assertJsonCount(0, 'locations');
        $zones = collect($feed->json('zones'))->keyBy('name');
        $this->assertSame(['Aversa', 'Napoli'], $zones->keys()->all());
        $this->assertCount(2, $zones['Aversa']['riders']);
        $this->assertSame(2, collect($zones['Aversa']['riders'])->firstWhere('id', $rider->id)['delivered_count']);
        $this->get('/rider-operations/'.$rider->id.'?date=2026-10-01&zone=Aversa')->assertOk()->assertViewHas('deliveries', fn ($items) => $items->count() === 2)->assertViewHas('orders', fn ($items) => $items->count() === 2);
    }

    public function test_daily_cash_uses_signed_ledger_and_external_retained_amounts(): void
    {
        $admin = $this->admin();
        $rider = User::factory()->create();
        $normal = $this->delivery($rider, 'Aversa');
        $reversed = $this->delivery($rider, 'Aversa');
        $external = $this->delivery($rider, 'Napoli');
        $external->forceFill(['shipping_type' => 'external', 'price_cents' => 2000, 'carrier_cost_cents' => 1400])->save();
        $this->payment($normal, $admin, 500);
        $this->payment($normal, $admin, -100);
        $this->payment($reversed, $admin, 500);
        $this->payment($reversed, $admin, -500);
        $this->payment($external, $admin, 2000, 600);
        $unrelated = $this->delivery(User::factory()->create(), 'Napoli');
        $this->payment($unrelated, $admin, 99999);
        $today = $this->delivery($rider, 'Napoli', '2026-10-02 10:00:00');
        $this->payment($today, $admin, 99999);
        $response = $this->actingAs($admin)->get('/rider-operations/'.$rider->id.'?date=2026-10-01')->assertOk()->assertViewHas('cash', 2400)->assertViewHas('retained', 1000)->assertViewHas('tariff', 3000)->assertSee('Storno o rettifica')->assertSee('Spedizione fuori regione');
        $response->assertViewHas('activity', fn ($items) => $items->count() === 6);
        $this->assertStringContainsString('10:00:00', $response->getContent());
    }

    public function test_legacy_admin_events_use_assignment_audits_instead_of_latest_rider(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $second->id, 'assigned_at' => Carbon::parse('2026-10-02 09:00:00', 'Europe/Rome')->utc(), 'status' => OrderStatus::OutForDelivery]);
        foreach ([['2026-10-01 08:00:00', null, $first->id], ['2026-10-02 09:00:00', $first->id, $second->id]] as [$at, $before, $after]) {
            EconomicAudit::create(['request_id' => (string) Str::uuid(), 'user_id' => $admin->id, 'entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'rider.assigned', 'before' => ['rider_id' => $before], 'after' => ['rider_id' => $after]]);
        }
        $audits = EconomicAudit::orderBy('id')->get();
        $audits[0]->forceFill(['created_at' => Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome')->utc()])->save();
        $audits[1]->forceFill(['created_at' => Carbon::parse('2026-10-02 09:00:00', 'Europe/Rome')->utc()])->save();
        $order->events()->make(['user_id' => $admin->id, 'status' => OrderStatus::PickedUp])->forceFill(['created_at' => Carbon::parse('2026-10-01 10:00:00', 'Europe/Rome')->utc()])->save();
        $this->actingAs($admin)->get('/rider-operations/'.$first->id.'?date=2026-10-01')->assertOk()->assertViewHas('activity', fn ($items) => $items->count() === 1);
        $this->get('/rider-operations/'.$second->id.'?date=2026-10-01')->assertOk()->assertViewHas('activity', fn ($items) => $items->isEmpty());
    }

    public function test_new_delivery_and_reassignment_events_capture_rider_and_zone(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $first->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Aversa']);
        $this->actingAs($admin)->patch('/orders/'.$order->id.'/rider', ['rider_id' => $second->id, 'version' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'rider_id' => $second->id, 'operational_zone' => 'Aversa', 'user_id' => $admin->id]);
        $this->patch('/orders/'.$order->id, ['status' => 'delivered', 'version' => 2])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'status' => 'delivered', 'rider_id' => $second->id, 'operational_zone' => 'Aversa', 'user_id' => $admin->id]);
        $this->get('/rider-operations/'.$second->id.'?date=2026-10-02')->assertOk()->assertViewHas('deliveries', fn ($items) => $items->count() === 1);
    }

    public function test_current_gps_cache_is_loaded_in_one_batch_with_database_cache(): void
    {
        config(['tracking.cache_store' => 'database']);
        $admin = $this->admin();
        $tracking = app(RiderTracking::class);
        for ($index = 0; $index < 12; $index++) {
            $rider = User::factory()->create();
            $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Zona '.$index]);
            $session = $tracking->start($order, $rider);
            $tracking->update($order, $rider, ['session' => $session, 'latitude' => 40.85, 'longitude' => 14.26, 'accuracy' => 12, 'recorded_at' => now()->toIso8601String()]);
        }
        $this->actingAs($admin);
        DB::enableQueryLog();
        $this->getJson('/rider-operations/feed')->assertOk()->assertJsonCount(12, 'locations')->assertJsonMissingPath('gps_session_id');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $gpsReads = collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], '"cache"') && str_starts_with(strtolower($query['query']), 'select'));
        $this->assertCount(1, $gpsReads);
        $this->assertLessThan(20, count($queries));
    }

    public function test_empty_history_and_many_zones_have_bounded_queries_and_safe_names(): void
    {
        $admin = $this->admin();
        $idle = User::factory()->create();
        $this->actingAs($admin)->get('/rider-operations/'.$idle->id.'?date=2026-10-01')->assertOk()->assertViewHas('cash', 0)->assertSee('Nessuna attività registrata');
        $this->getJson('/rider-operations/feed?date=2026-09-30')->assertOk()->assertJsonCount(0, 'zones');
        for ($index = 0; $index < 24; $index++) {
            $this->delivery(User::factory()->create(['name' => 'Rider con nome molto lungo '.$index.' <script>alert(1)</script>']), 'Zona operativa '.$index);
        }
        DB::enableQueryLog();
        $this->getJson('/rider-operations/feed?date=2026-10-01')->assertOk()->assertJsonCount(24, 'zones');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(20, count($queries));
        $this->get('/rider-operations?date=2026-10-01')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
