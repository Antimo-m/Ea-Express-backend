<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\Support\RiderOperations;
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
        $order = Order::factory()->create(['pickup_date' => substr($time, 0, 10), 'rider_id' => $rider->id, 'delivery_zone' => $zone, 'status' => OrderStatus::Delivered, 'delivered_at' => $at, 'assigned_at' => $at->copy()->subHour(), 'price_cents' => 500, 'parcel_value_cents' => 100000]);
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
        foreach (['/rider-operations', '/rider-operations/feed', '/rider-operations/detail', '/rider-operations/'.$rider->slug] as $path) {
            $this->getJson($path)->assertUnauthorized();
            $this->actingAs($rider)->getJson($path)->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))->getJson($path)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
        $this->actingAs($this->admin())->getJson('/rider-operations/feed?date=2026-10-03')->assertOk();
        $this->getJson('/rider-operations/feed?date=wrong')->assertUnprocessable();
    }

    public function test_operational_zones_and_assignments_do_not_depend_on_gps_freshness(): void
    {
        $admin = $this->admin();
        $sara = User::factory()->create(['name' => 'Destinatario Test A']);
        $marco = User::factory()->create(['name' => 'Marco']);
        $offline = User::factory()->create(['name' => 'Rider offline', 'is_active' => false]);
        $orders = collect();
        foreach ([$sara, $sara, $marco, $offline] as $index => $rider) {
            $orders->push(Order::factory()->create(['pickup_date' => '2026-10-02', 'rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => $index < 2 ? 'Zona nuova Pozzuoli' : 'Area Salerno']));
        }
        $order = $orders->first();
        $this->actingAs($sara)->postJson('/orders/'.$order->slug.'/location/session')->assertNotFound();
        $feed = $this->actingAs($admin)->getJson('/rider-operations/feed')->assertOk()->assertJsonPath('today', true)->assertJsonMissingPath('locations');
        $zones = collect($feed->json('zones'))->keyBy('name');
        $this->assertSame(2, $zones['Zona nuova Pozzuoli']['active']);
        $this->assertSame('active', collect($feed->json('riders'))->firstWhere('slug', $sara->slug)['state']);
        $this->travel(91)->seconds();
        $this->getJson('/rider-operations/feed')->assertJsonPath('summary.total', 4)->assertJsonMissingPath('locations');
    }

    public function test_history_places_rider_in_every_delivered_zone_and_ignores_current_zone_changes(): void
    {
        $rider = User::factory()->create(['name' => 'Destinatario Test A']);
        $other = User::factory()->create();
        $first = $this->delivery($rider, 'Aversa');
        $this->delivery($rider, 'Aversa', '2026-10-01 11:30:00');
        $this->delivery($rider, 'Napoli', '2026-10-01 12:30:00');
        $this->delivery($other, 'Aversa', '2026-10-01 13:30:00');
        $first->forceFill(['delivery_zone' => 'Zona cambiata'])->save();
        $feed = $this->actingAs($this->admin())->getJson('/rider-operations/feed?date=2026-10-01')->assertOk()->assertJsonPath('today', false)->assertJsonMissingPath('locations');
        $zones = collect($feed->json('zones'))->keyBy('name');
        $this->assertSame(['Aversa', 'Napoli'], $zones->keys()->all());
        $this->assertCount(2, $zones['Aversa']['riders']);
        $this->assertSame(3, collect($feed->json('riders'))->firstWhere('slug', $rider->slug)['delivered']);
        $this->get('/rider-operations/'.$rider->slug.'?date=2026-10-01&zone=Aversa')->assertOk()->assertViewHas('counts', fn ($counts) => $counts['completed'] === 2)->assertViewHas('orders', fn ($items) => $items->count() === 2);
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
        $this->actingAs($admin)->get('/rider-operations/'.$rider->slug.'?date=2026-10-01')->assertOk()->assertViewHas('accounting', fn ($data) => $data['cash'] === 0 && $data['retained'] === 0)->assertDontSee('Storno o rettifica')->assertDontSee('rider-order-events');
        $this->get('/rider-operations/'.$rider->slug.'?date=2026-10-02')->assertViewHas('accounting', fn ($data) => $data['cash'] === 102399 && $data['retained'] === 100999);
    }

    public function test_legacy_admin_events_use_assignment_audits_instead_of_latest_rider(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->create(['pickup_date' => '2026-10-01', 'rider_id' => $second->id, 'assigned_at' => Carbon::parse('2026-10-02 09:00:00', 'Europe/Rome')->utc(), 'status' => OrderStatus::OutForDelivery]);
        foreach ([['2026-10-01 08:00:00', null, $first->id], ['2026-10-02 09:00:00', $first->id, $second->id]] as [$at, $before, $after]) {
            EconomicAudit::create(['request_id' => (string) Str::uuid(), 'user_id' => $admin->id, 'entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'rider.assigned', 'before' => ['rider_id' => $before], 'after' => ['rider_id' => $after]]);
        }
        $audits = EconomicAudit::orderBy('id')->get();
        $audits[0]->forceFill(['created_at' => Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome')->utc()])->save();
        $audits[1]->forceFill(['created_at' => Carbon::parse('2026-10-02 09:00:00', 'Europe/Rome')->utc()])->save();
        $order->events()->make(['user_id' => $admin->id, 'status' => OrderStatus::PickedUp])->forceFill(['created_at' => Carbon::parse('2026-10-01 10:00:00', 'Europe/Rome')->utc()])->save();
        $order->load('events');
        $this->assertCount(1, app(RiderOperations::class)->activity($order->newCollection([$order]), $first->id));
        $this->assertCount(0, app(RiderOperations::class)->activity($order->newCollection([$order]), $second->id));
        $this->actingAs($admin)->get('/rider-operations/'.$first->slug.'?date=2026-10-01')->assertOk()->assertViewHas('orders', fn ($items) => $items->isEmpty());
        $this->get('/rider-operations/'.$second->slug.'?date=2026-10-01')->assertOk()->assertViewHas('orders', fn ($items) => $items->count() === 1)->assertDontSee('rider-order-events');
    }

    public function test_new_delivery_and_reassignment_events_capture_rider_and_zone(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = Order::factory()->create(['pickup_date' => '2026-10-02', 'rider_id' => $first->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Aversa']);
        $this->actingAs($admin)->patch('/orders/'.$order->slug.'/rider', ['rider_id' => $second->slug, 'version' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'rider_id' => $second->id, 'operational_zone' => 'Aversa', 'user_id' => $admin->id]);
        $this->patch('/orders/'.$order->slug, ['status' => 'delivered', 'version' => 2])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'status' => 'delivered', 'rider_id' => $second->id, 'operational_zone' => 'Aversa', 'user_id' => $admin->id]);
        $this->get('/rider-operations/'.$second->slug.'?date=2026-10-02')->assertOk()->assertViewHas('counts', fn ($counts) => $counts['completed'] === 1);
    }

    public function test_daily_operations_do_not_read_the_shared_gps_cache(): void
    {
        config(['cache.default' => 'database']);
        $admin = $this->admin();
        for ($index = 0; $index < 12; $index++) {
            $rider = User::factory()->create();
            $order = Order::factory()->create(['pickup_date' => '2026-10-02', 'rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Zona '.$index]);
        }
        $this->actingAs($admin);
        DB::enableQueryLog();
        $this->getJson('/rider-operations/feed')->assertOk()->assertJsonMissingPath('locations')->assertJsonMissingPath('gps_session_id');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $gpsReads = collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], '"cache"') && str_starts_with(strtolower($query['query']), 'select'));
        $this->assertCount(0, $gpsReads);
        $this->assertLessThan(20, count($queries));
    }

    public function test_empty_history_and_many_zones_have_bounded_queries_and_safe_names(): void
    {
        $admin = $this->admin();
        $idle = User::factory()->create();
        $this->actingAs($admin)->get('/rider-operations/'.$idle->slug.'?date=2026-10-01')->assertOk()->assertViewHas('accounting', fn ($data) => $data['cash'] === 0)->assertSee('Nessuna spedizione per questa selezione.');
        $this->getJson('/rider-operations/feed?date=2026-09-30')->assertOk()->assertJsonCount(0, 'zones');
        for ($index = 0; $index < 24; $index++) {
            $this->delivery(User::factory()->create(['name' => 'Rider con nome molto lungo '.$index.' <script>alert(1)</script>']), 'Zona operativa '.$index);
        }
        DB::enableQueryLog();
        $this->getJson('/rider-operations/feed?date=2026-10-01')->assertOk()->assertJsonCount(24, 'zones');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(20, count($queries));
        $this->get('/rider-operations?date=2026-10-01')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_custom_range_includes_both_boundary_days_and_keeps_zone_navigation_context(): void
    {
        $admin = $this->admin();
        $rider = User::factory()->create();
        Order::factory()->count(21)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-15', 'delivery_zone' => 'Aversa']);
        $last = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-17', 'delivery_zone' => 'Aversa']);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-14', 'delivery_zone' => 'Aversa', 'store_name' => 'Prima dell’intervallo']);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-18', 'delivery_zone' => 'Aversa', 'store_name' => 'Dopo l’intervallo']);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-16', 'delivery_zone' => 'Napoli']);
        $context = ['from' => '2026-09-15', 'to' => '2026-09-17', 'period' => 'custom', 'zone' => 'Aversa', 'view' => 'zone', 'q' => 'Aver', 'sort' => 'pending', 'selected' => 'Aversa', 'filter' => 'open', 'detail_page' => 2];
        $this->actingAs($admin);

        $feed = $this->getJson('/rider-operations/feed?from=2026-09-15&to=2026-09-17');

        $feed->assertOk()->assertJsonPath('date', '2026-09-15')->assertJsonPath('to', '2026-09-17')->assertJsonPath('summary.total', 23);
        $this->assertSame(22, collect($feed->json('zones'))->firstWhere('name', 'Aversa')['total']);
        $response = $this->get('/rider-operations/zone-orders?'.http_build_query([...$context, 'page' => 2]));
        $response->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 22 && $orders->count() === 2 && $orders->contains('slug', $last->slug))
            ->assertDontSee('Prima dell’intervallo')->assertDontSee('Dopo l’intervallo')
            ->assertViewHas('returnUrl', function (string $url) use ($context): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

                return array_diff_assoc(array_diff_key($context, ['zone' => true]), $parameters) === [];
            });
    }

    public function test_same_day_range_matches_the_existing_single_day_filter(): void
    {
        Order::factory()->create(['pickup_date' => '2026-10-01', 'delivery_zone' => 'Aversa']);
        Order::factory()->create(['pickup_date' => '2026-10-02', 'delivery_zone' => 'Aversa']);
        $this->actingAs($this->admin());

        $this->getJson('/rider-operations/feed?from=2026-10-01&to=2026-10-01')->assertOk()
            ->assertJsonPath('summary.total', 1)->assertJsonPath('date', '2026-10-01')->assertJsonPath('to', '2026-10-01');
        $this->getJson('/rider-operations/feed?date=2026-10-01')->assertOk()->assertJsonPath('summary.total', 1);
    }

    public function test_custom_range_rejects_reversed_or_incomplete_dates(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/rider-operations/feed?from=2026-11-15&to=2026-11-01')->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'La data finale deve essere uguale o successiva alla data iniziale.']);
        $this->getJson('/rider-operations/detail?from=2026-11-15')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/rider-operations/zone-orders?zone=Aversa&to=2026-11-15')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_range_controls_and_reset_preserve_other_operational_filters(): void
    {
        $context = ['from' => '2026-09-15', 'to' => '2026-09-17', 'period' => 'custom', 'view' => 'rider', 'q' => 'Rider', 'filter' => 'open', 'sort' => 'pending'];
        $this->actingAs($this->admin());

        $response = $this->get('/rider-operations?'.http_build_query($context));

        $response->assertOk()->assertSee('name="from"', false)->assertSee('name="to"', false)
            ->assertSee('min="2026-09-15"', false)
            ->assertSee(route('rider-operations.index', array_diff_key($context, ['from' => true, 'to' => true, 'period' => true])));
    }
}
