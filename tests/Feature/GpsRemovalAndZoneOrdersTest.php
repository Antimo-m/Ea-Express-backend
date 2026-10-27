<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GpsRemovalAndZoneOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('EA_UI_FIXTURE_DIR')) {
            $this->withoutVite();
        }
        $this->travelTo(Carbon::parse('2026-10-07 12:00', 'Europe/Rome'));
    }

    public function test_zone_page_filters_paginates_and_preserves_navigation_context(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        Order::factory()->count(25)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-15', 'delivery_zone' => 'Giugliano in Campania', 'store_name' => 'Azienda Test · Centro distribuzione alimentare dimostrativo della provincia e dei comuni limitrofi', 'recipient_name' => 'Destinatario Test Nominativo Esteso', 'pickup_address' => 'Via Test Dimostrativa per il ritiro numero 145', 'delivery_address' => 'Via Test Dimostrativa per il ritiro numero 145']);
        Order::factory()->create(['pickup_date' => '2026-10-07', 'delivery_zone' => 'Giugliano in Campania', 'store_name' => 'Fuori periodo']);
        Order::factory()->create(['pickup_date' => '2026-09-15', 'delivery_zone' => 'Napoli', 'store_name' => 'Altra zona']);
        $context = ['date' => '2026-09-15', 'period' => 'custom', 'zone' => 'Giugliano in Campania', 'view' => 'zone', 'selected' => 'Giugliano in Campania', 'q' => 'Giugliano', 'sort' => 'volume', 'scope' => 'programmed'];

        $response = $this->actingAs($admin)->get('/rider-operations/zone-orders?'.http_build_query($context));

        $response->assertViewHas('orders', fn ($orders) => $orders->total() === 25 && $orders->count() === 20)->assertSee('Azienda Test · Centro distribuzione alimentare dimostrativo della provincia e dei comuni limitrofi')->assertSee('Destinatario Test Nominativo Esteso')->assertSee('Dettaglio ordine')->assertDontSee('Fuori periodo')->assertDontSee('Altra zona')->assertViewHas('returnUrl', function (string $url) use ($context): bool {
            parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

            return array_diff_assoc(array_diff_key($context, ['zone' => true]), $parameters) === [];
        });
        $this->get('/rider-operations/zone-orders?'.http_build_query([...$context, 'page' => 2]))->assertViewHas('orders', fn ($orders) => $orders->count() === 5);
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if ($directory) {
            file_put_contents($directory.'/zone-orders.html', $response->getContent());
        }
    }

    public function test_rider_cards_show_current_assignments_only_and_use_public_slugs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $admin->id, 'pickup_date' => '2026-10-07', 'delivery_zone' => 'Caserta', 'store_name' => 'Riassegnato']);
        $order->events()->create(['rider_id' => $rider->id, 'user_id' => $admin->id, 'status' => OrderStatus::Accepted]);
        Order::factory()->count(25)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-10-07', 'delivery_zone' => 'Giugliano', 'store_name' => 'Ordine assegnato']);

        $response = $this->actingAs($admin)->get('/rider-operations/detail?date=2026-10-07&rider='.$rider->slug)->assertViewHas('orders', fn ($orders) => $orders->total() === 25)->assertSee('Ordine assegnato')->assertSee('Giugliano')->assertSee('Dettaglio ordine')->assertDontSee('Riassegnato');
        $this->getJson('/rider-operations/detail?rider='.$rider->id)->assertUnprocessable();
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if ($directory) {
            file_put_contents($directory.'/rider-mini-orders.html', $response->getContent());
        }
    }

    public function test_order_detail_returns_to_zone_page_with_its_pagination_and_context(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create();
        $context = '/rider-operations/zone-orders?zone=Giugliano&period=custom&date=2026-09-15&view=zone&selected=Giugliano&page=2';

        $this->actingAs($admin)->get('/orders/'.$order->slug.'?return_to='.urlencode($context))->assertSee('href="'.htmlspecialchars($context, ENT_QUOTES).'"', false);
        $this->get('/orders/'.$order->id)->assertNotFound();
    }

    public function test_zone_page_requires_admin_and_zone(): void
    {
        $this->get('/rider-operations/zone-orders?zone=Napoli')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->getJson('/rider-operations/zone-orders?zone=Napoli')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson('/rider-operations/zone-orders')->assertUnprocessable()->assertJsonValidationErrors('zone');
    }

    public function test_open_filter_and_backlog_scope_use_existing_operational_rules(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $open = Order::factory()->create(['pickup_date' => '2026-10-07', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::OutForDelivery]);
        Order::factory()->create(['pickup_date' => '2026-10-07', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::Delivered]);
        $backlog = Order::factory()->create(['rider_id' => $admin->id, 'pickup_date' => '2026-10-06', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::OutForDelivery, 'estimated_delivery_to' => '2026-10-06']);

        $this->actingAs($admin)->get('/rider-operations/zone-orders?zone=Napoli&filter=open')->assertViewHas('orders', fn ($orders) => $orders->modelKeys() === [$open->id]);
        $this->postJson('/orders/'.$backlog->slug.'/delay', ['version' => 1])->assertOk();
        $this->get('/rider-operations/zone-orders?zone=Napoli&scope=late')->assertViewHas('orders', fn ($orders) => $orders->modelKeys() === [$backlog->id]);
        $this->assertSame('2026-10-06', $backlog->fresh()->pickup_date->toDateString());
    }
}
