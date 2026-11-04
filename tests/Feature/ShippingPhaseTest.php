<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingPhaseTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['shipping_type' => 'external', 'delivery_province' => 'Torino', 'delivery_region' => 'Piemonte',
            'store_name' => 'Mittente sintetico', 'sender_type' => 'private', 'recipient_name' => 'Destinatario sintetico', 'recipient_phone' => '+390000000000',
            'pickup_address' => 'Via Prova', 'pickup_street_number' => '1', 'pickup_postal_code' => '80100', 'pickup_city' => 'Napoli',
            'delivery_address' => 'Via Test', 'delivery_street_number' => '2', 'delivery_postal_code' => '10121', 'delivery_city' => 'Torino',
            'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00',
            'parcel_count' => 1, 'parcel_value' => '100', 'category' => 'custom', 'content_description' => 'Ricambi per automobile', 'package_type' => 'standard', 'urgency' => 'standard'];
    }

    public function test_background_booking_rules_do_not_replace_the_form_navigation_and_errors_return_to_the_form(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($rider)->get('/orders/create')->assertOk();
        $this->getJson('/booking-rules')->assertOk();
        $this->assertSame(url('/orders/create'), session()->previousUrl());
        $this->withSession(['_previous' => ['url' => url('/booking-rules')]])
            ->post('/orders', [...$this->payload(), 'content_description' => ''])
            ->assertRedirect('/orders/create')->assertSessionHasErrors('content_description');
        $this->post('/orders', $this->payload())->assertOk()->assertViewHas('review', fn ($review) => $review['checkout_token'] === null && ! $review['quote']['available']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_external_staff_order_to_receipt_preserves_customer_price_and_reports_only_the_retained_share(): void
    {
        $this->freezeTime();
        $this->artisan('shipping:initialize-external-rate')->assertSuccessful();
        $rider = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($rider);
        $this->post('/orders', $this->staffCheckoutData($this->payload()))->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::sole();
        $this->assertSame('external', $order->shipping_type);
        $this->assertNull($order->weight_kg);
        $this->assertSame(700, $order->quoted_price_cents);
        $this->get('/orders/'.$order->slug)->assertOk()->assertSee('Ricambi per automobile');
        foreach (['accepted', 'rider_arriving', 'picked_up', 'out_for_delivery'] as $status) {
            $this->patch('/orders/'.$order->slug, ['version' => $order->fresh()->version, 'status' => $status, 'rider_id' => User::factory()->create()->slug])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->patch('/orders/'.$order->slug.'/carrier', ['version' => $order->fresh()->version, 'carrier_name' => 'Vettore sintetico', 'carrier_tracking' => 'SYNTH-001', 'carrier_status' => 'handed_over'])->assertSessionHasNoErrors();
        $this->patch('/orders/'.$order->slug, ['version' => $order->fresh()->version, 'status' => 'delivered'])->assertSessionHasNoErrors();
        $url = '/balance/'.$order->slug.'/payment';
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'receive'])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'receive', 'received_amount' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->assertDatabaseCount('payment_entries', 0);
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'receive', 'received_amount' => '7'])->assertOk();
        $this->assertDatabaseHas('payment_entries', ['order_id' => $order->id, 'amount_cents' => 700, 'ea_amount_cents' => 700]);
        $this->assertSame(700, $order->fresh()->price_cents);
        $this->assertSame(0, $order->fresh()->carrier_cost_cents);
        $this->get('/balance')->assertOk()->assertViewHas('cash', 700)->assertViewHas('operatingNet', 700)->assertViewHas('outstanding', 0);
        $this->get('/reports')->assertOk()->assertViewHas('operatingNet', 700)->assertViewHas('shippingEconomics', fn ($summary) => $summary['external_margin_cents'] === 700);
        $this->get('/track/'.$order->tracking_token)->assertOk()->assertSee('SYNTH-001')->assertDontSee('Quota EA-Express');
        $this->get(route('labels.index', ['ids' => [$order->slug]]))->assertOk()->assertSee('Ricambi per automobile')->assertDontSee('Scrivi il contenuto');
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'reverse', 'note' => 'Storno di prova'])->assertOk();
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(0, (int) PaymentEntry::sum('ea_amount_cents'));
        $this->get('/reports')->assertOk()->assertViewHas('operatingNet', 0);
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'restore', 'note' => 'Ripristino di prova'])->assertOk();
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'receive', 'received_amount' => '0'])->assertUnprocessable();
        $this->assertNull($order->fresh()->paid_at);
        $this->postJson($url, ['version' => $order->fresh()->version, 'action' => 'receive', 'received_amount' => '7'])->assertOk();
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(700, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(0, $order->fresh()->carrier_cost_cents);
        $this->get('/balance')->assertOk()->assertViewHas('operatingNet', 700);
    }

    public function test_default_external_rate_is_shared_editable_and_does_not_replace_regional_or_specific_rates(): void
    {
        $this->artisan('shipping:initialize-external-rate')->assertSuccessful();
        $rate = ShippingRate::where('is_default', true)->sole();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/rates')->assertOk()->assertJsonPath('rates.data.0.city', 'Fuori regione')->assertJsonPath('rates.data.0.price_cents', 700);
        $payload = [...$this->payload(), 'weight_kg' => 3, 'max_dimension_cm' => 40];
        $this->postJson('/api/v1/customer/orders', $this->checkoutData($payload))->assertCreated()->assertJsonPath('data.category_label', 'Ricambi per automobile');
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            file_put_contents($directory.'/rates.json', $this->getJson('/api/v1/customer/rates')->assertOk()->getContent());
        }
        $order = Order::sole();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'web')->postJson('/rates/'.$rate->slug, ['shipping_type' => 'external', 'city' => 'Fuori regione', 'price' => '8.50', 'carrier_cost' => '6', 'active' => true, 'source_reference' => 'Revisione sintetica'])->assertCreated();
        $this->artisan('shipping:initialize-external-rate')->assertSuccessful();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/rates')->assertOk()->assertJsonPath('rates.data.0.price_cents', 850)->assertJsonMissingPath('rates.data.0.carrier_cost_cents');
        $url = '/api/v1/customer/rates/quote?city=Torino&postal_code=10121&shipping_type=external&weight_kg=3&max_dimension_cm=40';
        $this->getJson($url)->assertOk()->assertJsonPath('price_cents', 850);
        $this->assertSame(700, $order->fresh()->price_cents);
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, $this->checkoutData([...$payload, 'version' => 1, 'content_description' => 'Libri antichi'], $order->slug))->assertOk()->assertJsonPath('data.category_label', 'Libri antichi');
        $this->patchJson('/api/v1/customer/orders/'.$order->slug, $this->checkoutData([...$payload, 'version' => 2, 'category' => 'documents', 'content_description' => null], $order->slug))->assertOk()->assertJsonPath('data.category_label', 'Documenti');
        ShippingRate::factory()->create(['shipping_type' => 'external', 'city' => 'Torino', 'city_key' => 'torino', 'price_cents' => 900]);
        $this->getJson($url)->assertOk()->assertJsonPath('price_cents', 900);
        ShippingRate::factory()->create(['shipping_type' => 'regional', 'city' => 'Torino', 'city_key' => 'torino', 'price_cents' => 400]);
        $this->getJson(str_replace('external', 'regional', $url))->assertOk()->assertJsonPath('price_cents', 400);
    }

    public function test_external_pending_payments_and_corrections_keep_both_amounts_consistent(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['shipping_type' => 'external', 'status' => OrderStatus::Delivered, 'price_cents' => 700, 'carrier_cost_cents' => 600, 'delivered_at' => now(), 'rider_id' => $admin->id]);
        $account = PendingAccount::factory()->create(['order_id' => $order->id, 'direction' => 'incoming', 'amount_cents' => 700]);
        $this->actingAs($admin);
        $key = (string) Str::uuid();
        $data = ['version' => 1, 'amount' => '7', 'ea_amount' => '1', 'submission_key' => $key];
        $this->postJson('/pending/'.$account->slug.'/settlements', $data)->assertOk();
        $this->postJson('/pending/'.$account->slug.'/settlements', $data)->assertOk();
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertSame(600, $order->fresh()->carrier_cost_cents);
        $settlement = $account->settlements()->sole();
        $url = '/pending/'.$account->slug.'/settlements/'.$settlement->slug;
        $this->patchJson($url, ['version' => $account->fresh()->version, 'amount' => '7', 'ea_amount' => '2', 'reason' => 'Correzione quota sintetica'])->assertOk();
        $this->assertDatabaseHas('payment_entries', ['order_id' => $order->id, 'amount_cents' => 700, 'ea_amount_cents' => 200]);
        $this->assertSame(600, $order->fresh()->carrier_cost_cents);
        $this->get('/balance')->assertOk()->assertViewHas('operatingNet', 700);
        $this->postJson('/pending/'.$account->slug.'/settlements', [...$data, 'ea_amount' => '3'])->assertConflict();
    }

    public function test_staff_form_has_distinct_sections_confirmation_and_custom_content_without_measurements(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/orders/create')->assertOk()
            ->assertSee('Punto di ritiro')->assertSee('Punto di consegna')->assertSee('Data e orario')
            ->assertSee('Continua modifica')->assertSee('Conferma annullamento')->assertSee('Scrivi il contenuto')
            ->assertDontSee('name="weight_kg"', false)->assertDontSee('name="max_dimension_cm"', false);
    }

    public function test_customer_profile_uses_account_name_and_returns_to_directory(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Negozio sintetico']);
        Order::factory()->create(['customer_id' => $customer->id, 'price_cents' => 500]);
        $response = $this->actingAs($admin)->get('/customers/'.$customer->slug)->assertOk()->assertSee('Negozio sintetico')
            ->assertSee('href="'.route('customers.index').'"', false)->assertDontSee('Account #');
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            file_put_contents($directory.'/customer-detail.html', $response->getContent());
            file_put_contents($directory.'/progress.html', $this->get('/orders/in-progress')->assertOk()->getContent());
        }
    }
}
