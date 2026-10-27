<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TerritorialRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['store_name' => 'Mittente', 'sender_type' => 'private', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031', 'pickup_city' => 'Aversa', 'delivery_address' => 'Via Roma', 'delivery_street_number' => '2', 'delivery_postal_code' => '81031', 'delivery_city' => 'Aversa', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'parcel_value' => '50', 'category' => 'documents', 'package_type' => 'standard', 'urgency' => 'standard'];
    }

    public function test_a_corrupt_rate_cannot_validate_a_cap_from_another_municipality(): void
    {
        ShippingRate::factory()->create(['city' => 'Torino', 'city_key' => 'torino', 'postal_code' => '80014', 'price_cents' => 500]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=80014')->assertOk()->assertJsonPath('available', false)->assertJsonPath('price_cents', null)->assertJsonPath('territory_valid', false)
            ->assertJsonPath('errors.delivery_postal_code.0', 'Il CAP inserito non corrisponde al Comune di consegna selezionato. Verifica il CAP e riprova.');
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'delivery_city' => 'Torino', 'delivery_postal_code' => '80014'])->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->postJson('/api/v1/customer/orders', [...$this->payload(), 'delivery_city' => 'Torino', 'delivery_postal_code' => '80014', 'checkout_token' => 'alterato'])->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unknown_municipalities_unknown_caps_and_inactive_rates_do_not_produce_prices(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        foreach (['city=Aversa&postal_code=81031', 'city=Aversa&postal_code=99999', 'city=Inesistente&postal_code=81031', 'city=Caserta&postal_code=81100'] as $query) {
            $this->getJson('/rates/quote?'.$query)->assertOk()->assertJsonPath('available', false)->assertJsonPath('price_cents', null);
        }
        $rate->update(['active' => true]);
        $this->getJson('/rates/quote?city=%20AVERSA%20&postal_code=81031')->assertJsonPath('price_cents', 500);
        $rate->update(['territory_review_required' => true]);
        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('available', false);
    }

    public function test_multicap_rates_keep_every_code_and_require_an_explicit_cap(): void
    {
        ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'postal_code' => null, 'postal_codes' => ['80121', '80122'], 'price_cents' => 600]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/rates/quote?city=Napoli')->assertJsonPath('available', false);
        foreach (['80121', '80122'] as $code) {
            $this->getJson('/rates/quote?city=Napoli&postal_code='.$code)->assertJsonPath('price_cents', 600);
        }
        $this->getJson('/rates/quote?city=Napoli&postal_code=80123')->assertJsonPath('territory_valid', true)->assertJsonPath('available', false);
        $this->getJson('/rates/quote?city=Napoli&postal_code=80100')->assertJsonPath('territory_valid', false);
        $this->assertSame(ShippingQuote::cityKey('Sant’Àntimo'), ShippingQuote::cityKey(" SANT'ANTIMO "));
    }

    public function test_backfill_is_repeatable_and_preserves_every_nonterritorial_attribute(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'area' => 'Caserta', 'postal_code' => null, 'price_cents' => 875, 'active' => false, 'source_reference' => 'Tariffa storica']);
        $multi = ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'area' => 'Napoli / Nola', 'postal_code' => null]);
        $uncertain = ShippingRate::factory()->create(['city' => 'Camposanto', 'city_key' => 'camposanto', 'area' => 'Napoli / Nola', 'postal_code' => null]);
        $before = $rate->fresh()->getAttributes();
        $this->artisan('shipping:backfill-postal-codes')->assertSuccessful();
        $this->assertNull($rate->fresh()->postal_code);
        $this->artisan('shipping:backfill-postal-codes', ['--apply' => true])->assertSuccessful();
        $after = $rate->fresh()->getAttributes();
        foreach (array_diff(array_keys($before), ['postal_code', 'postal_codes', 'city', 'city_key', 'territory_review_required']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertSame('81031', $rate->fresh()->postal_code);
        $this->assertSame(['81031'], $rate->fresh()->postal_codes);
        $this->assertNull($multi->fresh()->postal_code);
        $this->assertContains('80121', $multi->fresh()->postal_codes);
        $this->assertContains('80147', $multi->fresh()->postal_codes);
        $this->assertTrue($uncertain->fresh()->territory_review_required);
        $this->assertNull($uncertain->fresh()->postal_code);
        $this->artisan('shipping:backfill-postal-codes', ['--apply' => true])->assertSuccessful();
        $this->assertSame($after, $rate->fresh()->getAttributes());
    }

    public function test_staff_and_customer_creations_validate_territory_and_ignore_delivery_time_preferences(): void
    {
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $review = $this->post('/orders', [...$this->payload(), 'delivery_window' => '17:30'])->assertOk()->viewData('review');
        $this->post('/orders', [...$review['data'], 'checkout_token' => $review['checkout_token']])->assertRedirect();
        $this->assertNull(Order::sole()->delivery_window);
        $this->postJson('/orders', [...$this->payload(), 'delivery_postal_code' => '80014'])->assertUnprocessable()->assertJsonValidationErrors('delivery_postal_code');
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $data = $this->checkoutData([...$this->payload(), 'delivery_window' => '18:00']);
        $this->postJson('/api/v1/customer/orders', $data)->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertNull($order->delivery_window);
        $order->update(['delivery_window' => 'Preferenza storica']);
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, $this->checkoutData([...$this->payload(), 'version' => 1, 'delivery_window' => '19:00'], $order->slug))->assertOk();
        $this->assertSame('Preferenza storica', $order->fresh()->delivery_window);
    }

    public function test_external_service_checks_territory_without_exposing_the_actual_carrier(): void
    {
        ShippingRate::factory()->create(['shipping_type' => 'external', 'is_default' => true, 'city' => 'Fuori regione', 'city_key' => 'fuori regione', 'carrier_name' => 'Vettore riservato', 'carrier_cost_cents' => 300, 'price_cents' => 700, 'postal_code' => null]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external')->assertJsonPath('price_cents', 700)->assertJsonMissingPath('carrier_name')->assertJsonMissingPath('carrier_cost_cents');
        $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=80014&shipping_type=external')->assertJsonPath('available', false);
        $this->getJson('/api/v1/customer/rates')->assertJsonMissingPath('rates.data.0.carrier_name');
        $this->postJson('/api/v1/customer/orders', $this->checkoutData([...$this->payload(), 'shipping_type' => 'external', 'delivery_city' => 'Torino', 'delivery_postal_code' => '10121', 'delivery_province' => 'TO', 'delivery_region' => 'Piemonte']))->assertCreated()->assertJsonMissingPath('data.carrier_name');
        $this->assertSame('Vettore riservato', Order::sole()->carrier_name);
    }

    public function test_customer_statistics_only_describe_own_activity_and_never_return_tariff_distribution(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Received]);
        Order::factory()->count(3)->create();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?tariff=500')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('delivered', 1)->assertJsonPath('in_progress', 1)
            ->assertJsonMissingPath('prices')->assertJsonMissingPath('detail')->assertJsonMissingPath('carrier_cost_cents')->assertJsonStructure(['gross_cents', 'delivered_spend_cents', 'net_cents'])->assertJsonMissingPath('external_margin_cents');
    }

    public function test_rate_editor_saves_multiple_caps_rejects_foreign_caps_and_preserves_hidden_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rate = ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'postal_code' => null, 'postal_codes' => ['80121'], 'zone' => 'centro', 'street' => 'via roma', 'delivery_days_min' => 2, 'delivery_days_max' => 3]);
        $this->actingAs($admin);
        $payload = ['shipping_type' => 'regional', 'city' => 'Napoli', 'area' => 'Napoli', 'postal_codes' => ['80121', '80122'], 'price' => '6', 'delivery_time' => '2–3 giorni', 'active' => true];
        $this->postJson('/rates/'.$rate->slug, [...$payload, 'postal_codes' => ['80014']])->assertUnprocessable()->assertJsonValidationErrors('postal_codes');
        $id = $this->postJson('/rates/'.$rate->slug, $payload)->assertCreated()->json('data.id');
        $new = ShippingRate::findOrFail($id);
        $this->assertSame(['80121', '80122'], $new->postal_codes);
        $this->assertSame('centro', $new->zone);
        $this->assertSame('via roma', $new->street);
        $this->assertSame(2, $new->delivery_days_min);
        $this->assertSame($rate->id, $new->supersedes_id);
    }

    public function test_revised_views_use_real_pagination_and_place_cash_below_the_accounting_table(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $places = json_decode(file_get_contents(resource_path('italian-postal-codes.json')), true)['municipalities'];
        foreach (array_slice($places, 0, 21) as $place) {
            ShippingRate::factory()->create(['city' => $place['name'], 'city_key' => ShippingQuote::cityKey($place['name']), 'postal_code' => $place['postal_codes'][0]]);
        }
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031']);
        ShippingRate::factory()->create(['is_default' => true, 'shipping_type' => 'external', 'city' => 'Fuori regione', 'city_key' => 'fuori regione', 'carrier_name' => 'Vettore riservato']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'created_by' => $admin->id, 'price_cents' => 500, 'store_name' => 'Negozio di prova']);
        $this->actingAs($admin);
        $this->postJson('/movements', ['kind' => 'cash', 'amount' => '15', 'submission_key' => (string) Str::uuid()])->assertCreated();
        $pages = [
            'balance' => $this->get('/balance')->assertOk()->assertSeeInOrder(['Prospetto contabile', 'id="delivery-details"', 'Movimenti e storico'], false)->assertSee('ledger=cash', false),
            'rates' => $this->get('/rates')->assertOk()->assertSee('1 / 2'),
            'rates-next' => $this->get('/rates?page=2')->assertOk()->assertSee('2 / 2'),
            'customers' => $this->get('/customers')->assertOk()->assertSee('<h1>Clienti</h1>', false),
            'customer-long' => $this->get('/customers/'.$customer->slug.'?period=custom&from=2026-01-01&to=2026-12-31')->assertOk(),
            'reports' => $this->get('/reports')->assertOk()->assertSee('filter-toolbar'),
            'create' => $this->get('/orders/create')->assertOk()->assertDontSee('name="delivery_window"', false),
        ];
        $this->actingAs($customer, 'customer');
        $api = [
            'statistics' => $this->getJson('/api/v1/customer/statistics')->assertOk(),
            'auth' => $this->getJson('/api/v1/customer/auth/me')->assertOk(),
            'order' => $this->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk(),
            'rates' => $this->getJson('/api/v1/customer/rates')->assertOk(),
            'quote-valid' => $this->getJson('/api/v1/customer/rates/quote?city=Aversa&postal_code=81031')->assertOk(),
            'quote-invalid' => $this->getJson('/api/v1/customer/rates/quote?city=Torino&postal_code=80014')->assertOk(),
        ];
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            foreach ($pages as $name => $page) {
                file_put_contents($directory.'/'.$name.'.html', $page->getContent());
            }
            foreach ($api as $name => $response) {
                file_put_contents($directory.'/'.$name.'.json', $response->getContent());
            }
        }
    }
}
