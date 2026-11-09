<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentCardInformationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, OrderStatus, bool}> */
    public static function shipmentViews(): array
    {
        return [
            'admin' => ['/dashboard', 'dashboard-order-preview', OrderStatus::Accepted, false],
            'rider' => ['/dashboard', 'dispatch-row', OrderStatus::Accepted, true],
            'incoming' => ['/orders/incoming', 'operational-order-main', OrderStatus::Received, false],
            'in-progress' => ['/orders/in-progress', 'operational-order-main', OrderStatus::Accepted, false],
            'archive' => ['/orders/history', 'history-order', OrderStatus::Delivered, false],
            'rider-day' => ['/rider-operations/{rider}', 'rider-delivery-card', OrderStatus::Accepted, false],
            'operation-detail' => ['/rider-operations/detail?rider={rider}', 'operation-mini-card', OrderStatus::Accepted, false],
            'pickup-agenda' => ['/pickups/accounts/{customer}/orders?date=2026-11-09', 'pickup-order-card', OrderStatus::Accepted, false],
        ];
    }

    #[DataProvider('shipmentViews')]
    public function test_each_shipment_card_exposes_complete_operational_information(string $path, string $cardClass, OrderStatus $status, bool $asRider): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 09:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['name' => 'Sara']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Mario Rossi']);
        $order = Order::factory()->create([
            'created_by' => $admin->id, 'customer_id' => $customer->id, 'store_name' => 'Mario Rossi',
            'rider_id' => $status === OrderStatus::Received ? null : $rider->id, 'status' => $status,
            'reference' => 'EA-2026-CARD', 'recipient_name' => 'Giuseppe Bianchi',
            'pickup_date' => '2026-11-09', 'pickup_from' => '10:00', 'pickup_to' => '12:30',
            'pickup_address' => 'Via Roma', 'pickup_street_number' => '12', 'pickup_postal_code' => '80100', 'pickup_city' => 'Napoli',
            'delivery_address' => 'Via Milano', 'delivery_street_number' => '24', 'delivery_postal_code' => '81100', 'delivery_city' => 'Caserta',
            'delivered_at' => $status === OrderStatus::Delivered ? now() : null,
        ]);
        $path = str_replace(['{rider}', '{customer}'], [$rider->slug, $customer->slug], $path);

        $response = $this->actingAs($asRider ? $rider : $admin)->get($path)->assertOk();

        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $nodes = (new \DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$cardClass.' ")]');
        $this->assertSame(1, $nodes->length);
        $text = preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent);
        foreach (['EA-2026-CARD', 'Mario Rossi', 'Giuseppe Bianchi', 'Via Roma 12', '80100 Napoli', 'Via Milano 24', '81100 Caserta', '09/11/2026', '10:00–12:30', $status->label(), $status === OrderStatus::Received ? 'In attesa di assegnazione' : 'Sara'] as $detail) {
            $this->assertStringContainsString($detail, $text, $detail.' missing from '.$cardClass);
        }
        $response->assertSee(route('orders.show', $order));

        if (($directory = getenv('EA_UI_FIXTURE_DIR')) && $cardClass !== 'operation-mini-card' && $cardClass !== 'pickup-order-card') {
            file_put_contents($directory.'/shipment-cards-'.$this->dataName().'.html', $response->getContent());
        }
    }

    public function test_dashboard_rider_names_are_eager_loaded_as_the_number_of_visible_cards_grows(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $riders = User::factory()->count(3)->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $riders[0]->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($admin);
        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->get('/dashboard')->assertOk()->assertSee($riders[0]->name);
            $oneCardQueries = count(DB::getQueryLog());
            foreach ([$riders[1], $riders[2]] as $rider) {
                Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
            }
            DB::flushQueryLog();

            $response = $this->get('/dashboard')->assertOk();

            $this->assertSame($oneCardQueries, count(DB::getQueryLog()));
            foreach ($riders as $rider) {
                $response->assertSee($rider->name);
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_card_addresses_escape_customer_supplied_html(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['pickup_address' => '<script>alert(1)</script>', 'delivery_address' => '<img src=x onerror=alert(2)>']);

        $this->actingAs($admin)->get('/orders/incoming')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }
}
