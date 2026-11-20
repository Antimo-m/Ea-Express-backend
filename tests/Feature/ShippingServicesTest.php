<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShippingServicesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['shipping_type' => 'external', 'delivery_province' => 'Torino', 'delivery_region' => 'Piemonte', 'weight_kg' => '3.50', 'max_dimension_cm' => '40', 'parcel_value' => '100', 'recipient_name' => 'Destinatario di prova', 'recipient_phone' => '+393331234567', 'pickup_address' => 'Via Prova', 'pickup_city' => 'Napoli', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'delivery_address' => 'Via Test', 'delivery_city' => 'Torino', 'delivery_street_number' => '20', 'delivery_postal_code' => '10121', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'other', 'urgency' => 'standard', 'package_type' => 'standard', 'sender_type' => 'online_shop'];
    }

    private function rate(array $attributes = []): ShippingRate
    {
        return ShippingRate::factory()->create([...['shipping_type' => 'external', 'city' => 'Torino', 'city_key' => 'torino', 'price_cents' => 700, 'carrier_cost_cents' => 600, 'carrier_name' => 'Vettore test', 'max_weight_kg' => 5, 'max_dimension_cm' => 60, 'delivery_days_min' => 2, 'delivery_days_max' => 4], ...$attributes]);
    }

    public function test_external_checkout_persists_server_economics_and_hides_internal_data_everywhere(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Negozio di prova']);
        $this->rate();
        $this->actingAs($customer, 'customer');
        $query = '/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external&weight_kg=3.5&max_dimension_cm=40';
        $this->getJson($query)->assertOk()->assertJsonPath('price_cents', 700)->assertJsonMissingPath('carrier_cost_cents')->assertJsonMissingPath('source_reference');
        $this->getJson('/api/v1/customer/rates')->assertOk()->assertJsonMissingPath('rates.data.0.carrier_cost_cents')->assertJsonMissingPath('rates.data.0.source_reference');
        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->payload())->assertOk()->assertJsonMissingPath('quote.carrier_cost_cents')->json();
        $this->postJson('/api/v1/customer/orders', [...$review['data'], 'checkout_token' => $review['checkout_token'], 'carrier_cost_cents' => 1, 'carrier_name' => 'Forgiato', 'price_cents' => 1])->assertCreated()->assertJsonPath('data.price_cents', 700)->assertJsonMissingPath('data.carrier_name')->assertJsonMissingPath('data.rate_snapshot.carrier_cost_cents');
        $order = Order::sole();
        $this->assertSame(600, $order->carrier_cost_cents);
        $this->assertSame('external', $order->shipping_type);
        $this->assertNotNull($order->estimated_delivery_to);
        $this->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()->assertJsonMissingPath('data.carrier_cost_cents');
        $this->postJson('/api/v1/customer/orders', [...$review['data'], 'checkout_token' => $review['checkout_token']])->assertSuccessful();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_weight_service_and_ambiguity_cannot_select_an_incompatible_rate(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->rate();
        $this->rate(['max_weight_kg' => 10, 'price_cents' => 1000]);
        $this->actingAs($customer, 'customer');
        $url = '/api/v1/customer/rates/quote?city=Torino&postal_code=10121&max_dimension_cm=40';
        $this->getJson($url.'&shipping_type=external&weight_kg=3')->assertJsonPath('price_cents', 700);
        $this->getJson($url.'&shipping_type=external&weight_kg=6')->assertJsonPath('price_cents', 1000);
        $this->getJson($url.'&shipping_type=external&weight_kg=11')->assertJsonPath('available', false);
        $this->getJson($url.'&shipping_type=regional&weight_kg=3')->assertJsonPath('available', false);
        $this->getJson($url.'&shipping_type=external')->assertJsonPath('available', false);
        $this->rate();
        $this->getJson($url.'&shipping_type=external&weight_kg=3')->assertJsonPath('available', false);
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'weight_kg' => null, 'delivery_region' => null])->assertUnprocessable()->assertJsonValidationErrors('delivery_region')->assertJsonMissingPath('errors.weight_kg');
    }

    public function test_tariff_revision_preserves_order_cost_and_rejects_stale_checkout(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rate = $this->rate();
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/customer/orders', $this->checkoutData($this->payload()))->assertCreated();
        $order = Order::sole();
        $stale = $this->checkoutData($this->payload());
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'web')->post('/rates/'.$rate->slug, ['city' => 'Torino', 'shipping_type' => 'external', 'price' => '9', 'carrier_cost' => '7', 'carrier_name' => 'Altro vettore', 'max_weight_kg' => 5, 'max_dimension_cm' => 60, 'delivery_days_min' => 3, 'delivery_days_max' => 5, 'source_reference' => 'Listino test revisionato', 'active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(600, $order->fresh()->carrier_cost_cents);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', $stale)->assertConflict();
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, $this->checkoutData([...$this->payload(), 'weight_kg' => '3.5', 'version' => 1, 'customer_notes' => 'Citofono test'], $order->slug))->assertOk()->assertJsonPath('data.price_cents', 700);
        $this->assertSame(600, $order->fresh()->carrier_cost_cents);
    }

    public function test_external_quote_requires_measurements_even_for_an_unlimited_rate(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->rate(['max_weight_kg' => null, 'max_dimension_cm' => null]);
        $this->actingAs($customer, 'customer');
        $url = '/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external';

        foreach (['', '&weight_kg=3', '&max_dimension_cm=40'] as $measurements) {
            $this->getJson($url.$measurements)->assertOk()
                ->assertJsonPath('available', false)
                ->assertJsonPath('price_cents', null)
                ->assertJsonPath('reason', 'Inserisci peso e dimensioni per verificare la tariffa fuori regione.');
        }
        $this->getJson($url.'&weight_kg=3&max_dimension_cm=40')->assertOk()->assertJsonPath('price_cents', 700);
    }

    public function test_regional_csv_import_preserves_external_and_specialized_rates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $external = $this->rate(['area' => 'Zona test']);
        $specialized = [];
        foreach ([['max_weight_kg' => 5], ['max_dimension_cm' => 60], ['zone' => 'centro'], ['street' => 'via test']] as $criteria) {
            $specialized[] = ShippingRate::factory()->create([
                'city' => 'Torino', 'city_key' => 'torino', 'area' => 'Zona test',
                'shipping_type' => 'regional', ...$criteria,
            ]);
        }
        $file = tempnam(sys_get_temp_dir(), 'ea-rates-test-');

        try {
            file_put_contents($file, "area,city,postal_code,price,delivery_time,source_reference\nZona test,Torino,,9.00,48 ore,Fixture sintetica\n");
            $arguments = ['file' => $file, '--user' => $admin->id];
            $this->artisan('shipping:import-rates', $arguments)->assertSuccessful();
            $this->artisan('shipping:import-rates', $arguments)->assertSuccessful();

            $this->assertDatabaseCount('shipping_rates', 6);
            $this->assertDatabaseCount('economic_audits', 1);
            foreach ([$external, ...$specialized] as $rate) {
                $this->assertTrue($rate->fresh()->active);
                $this->assertNull($rate->fresh()->successor);
            }
            $this->assertSame(600, $external->fresh()->carrier_cost_cents);
            $this->assertDatabaseHas('shipping_rates', [
                'shipping_type' => 'regional', 'city_key' => 'torino', 'price_cents' => 900,
                'max_weight_kg' => null, 'max_dimension_cm' => null, 'zone' => null, 'street' => null,
            ]);
        } finally {
            unlink($file);
        }
    }

    public function test_external_delivery_reports_saved_margin_and_keeps_carrier_updates_authorized(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->rate();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders', $this->checkoutData($this->payload()))->assertCreated();
        $order = Order::sole();
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['accepted', 'rider_arriving', 'picked_up', 'in_transit', 'out_for_delivery'] as $status) {
            $this->actingAs($status === 'accepted' ? $admin : $rider, 'web');
            $this->patch('/orders/'.$order->slug, ['version' => $order->fresh()->version, 'status' => $status, 'rider_id' => $rider->slug])->assertSessionHasNoErrors();
        }
        $this->patch('/orders/'.$order->slug.'/carrier', ['version' => $order->fresh()->version, 'carrier_tracking' => 'TEST-123', 'carrier_status' => 'handed_over'])->assertSessionHasNoErrors()->assertRedirect();
        $this->patch('/orders/'.$order->slug, ['version' => $order->fresh()->version, 'status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertSame('delivered', $order->fresh()->carrier_status);
        $this->actingAs($admin)->get('/reports')->assertOk()->assertViewHas('shippingEconomics', fn ($summary) => $summary['external_revenue_cents'] === 700 && $summary['carrier_cost_cents'] === 600 && $summary['external_margin_cents'] === 100);
        $this->get('/balance')->assertOk()->assertViewHas('shippingEconomics', fn ($summary) => $summary['shipping_margin_cents'] === 100);
        $this->get('/track/'.$order->fresh()->tracking_token)->assertOk()->assertSee('TEST-123')->assertDontSee($customer->name)->assertDontSee('Costo vettore');
        $this->get('/orders/'.$order->slug)->assertOk()->assertSee('Tariffa prevista');
        $this->get(route('labels.index', ['ids' => [$order->slug]]))->assertOk()->assertSee('Provincia:')->assertSee('Piemonte')->assertSee('Numero cellulare:')->assertDontSee('Costo vettore');
        $this->patchJson('/orders/'.$order->slug.'/carrier', ['version' => $order->fresh()->version, 'carrier_tracking' => 'Changed', 'carrier_status' => 'in_transit'])->assertConflict();
    }

    public function test_statistics_are_tenant_scoped_and_compare_the_selected_period(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 25)->setTime(12, 0));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-10 10:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 700, 'parcel_value_cents' => 10000, 'shipping_type' => 'external', 'carrier_cost_cents' => 600]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-11 10:00:00', 'status' => OrderStatus::Received, 'price_cents' => 500, 'parcel_value_cents' => 5000]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-09 10:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 300, 'parcel_value_cents' => 5000]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-10 12:00:00', 'status' => OrderStatus::Cancelled, 'price_cents' => 99999, 'parcel_value_cents' => 99999]);
        Order::factory()->create(['customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'created_at' => '2026-09-10 10:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 99999]);
        $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-10&to=2026-09-11')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('delivered', 1)->assertJsonMissingPath('shipping_spend_cents')->assertJsonPath('external_count', 1)->assertJsonPath('previous_from', '2026-09-08')->assertJsonPath('previous_to', '2026-09-09')->assertJsonPath('changes.total', 200)->assertJsonPath('changes.delivered', 0)->assertJsonPath('trend.0.delivered', 1)->assertJsonMissingPath('carrier_cost_cents');
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            file_put_contents($directory.'/statistics.json', $response->getContent());
            file_put_contents($directory.'/auth.json', $this->getJson('/api/v1/customer/auth/me')->assertOk()->getContent());
            $fixture = Order::where('customer_id', $customer->id)->where('shipping_type', 'external')->firstOrFail();
            file_put_contents($directory.'/order.json', $this->getJson('/api/v1/customer/orders/'.$fixture->slug)->assertOk()->getContent());
            $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web');
            file_put_contents($directory.'/labels.html', $this->get(route('labels.index', ['ids' => [$fixture->slug]]))->assertOk()->getContent());
            file_put_contents($directory.'/reports.html', $this->get('/reports')->assertOk()->getContent());
            file_put_contents($directory.'/create.html', $this->get('/orders/create')->assertOk()->getContent());
            file_put_contents($directory.'/rates.html', $this->get('/rates')->assertOk()->getContent());
        }
        $this->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-12&to=2026-09-11')->assertUnprocessable();
    }

    public function test_other_customers_and_riders_cannot_update_carrier_or_read_orders(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $owner->id, 'rider_id' => User::factory(), 'status' => OrderStatus::PickedUp, 'shipping_type' => 'external']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertNotFound();
        $this->actingAs(User::factory()->create(), 'web')->patchJson('/orders/'.$order->slug.'/carrier', ['version' => 1, 'carrier_tracking' => 'FORGED', 'carrier_status' => 'handed_over'])->assertForbidden();
        $this->assertNull($order->fresh()->carrier_tracking);
    }

    public function test_statistics_keep_response_and_query_count_bounded_with_five_thousand_orders(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 25)->setTime(12, 0));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $creator = User::factory()->create();
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = Order::factory()->count(500)->make(['customer_id' => $customer->id, 'created_by' => $creator->id, 'rider_id' => null, 'status' => OrderStatus::Delivered, 'created_at' => '2026-09-10 10:00:00', 'price_cents' => 700, 'parcel_value_cents' => 10000])->map(fn ($order) => $order->getAttributes())->all();
            Order::insert($rows);
        }
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics')->assertOk()->assertJsonPath('total', 5000)->assertJsonPath('delivered', 5000)->assertJsonPath('net_cents', 46500000)->assertJsonCount(25, 'trend');
        $elapsed = (hrtime(true) - $started) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(40, $queries);
        $this->assertLessThan(40000, strlen($response->getContent()));
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            file_put_contents($directory.'/many.json', $response->getContent());
            file_put_contents($directory.'/scale.json', json_encode(['orders' => 5000, 'milliseconds' => round($elapsed, 1), 'queries' => $queries, 'response_bytes' => strlen($response->getContent()), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1)]));
        }
    }
}
