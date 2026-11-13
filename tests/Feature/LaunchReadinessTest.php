<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    private string $api = '/api/v1/customer';

    public function test_registration_normalizes_unicode_and_whitespace_without_corrupting_legitimate_names(): void
    {
        $this->postJson($this->api.'/auth/register', [...$this->registration(), 'name' => "\u{00A0} Caffe\u{0300}  &\tArtigianato \u{2003}", 'business_type' => "  Articoli\u{00A0} da  regalo  "])
            ->assertAccepted();
        $this->assertDatabaseHas('users', ['email' => $this->registration()['email'], 'name' => 'Caffè & Artigianato', 'business_type' => 'Articoli da regalo']);
        $this->assertDatabaseHas('users', ['email' => 'launch@example.test', 'name' => 'Caffè & Artigianato']);
    }

    public function test_direct_registration_rejects_obfuscated_business_names_and_descriptions(): void
    {
        $this->postJson($this->api.'/auth/register', [...$this->registration(), 'name' => 'Negozio c0c41n4', 'business_description' => 'h.a.s.h.i.s.h'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'business_description'])
            ->assertJsonPath('errors.name.0', config('portal_text_policy.message'));
        $this->assertDatabaseMissing('users', ['email' => 'launch@example.test']);
        $this->postJson($this->api.'/auth/register', [...$this->registration(), 'name' => "\u{00A0}\u{2003}"])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->api.'/auth/register', [...$this->registration(), 'sender_type' => 'private', 'name' => 'Maria Troia'])
            ->assertAccepted();
        $this->assertDatabaseHas('users', ['email' => $this->registration()['email'], 'name' => 'Maria Troia']);
    }

    public function test_profile_and_booking_cannot_bypass_business_name_validation(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'business', 'name' => 'Nome valido']);
        $this->actingAs($customer, 'customer')->patchJson($this->api.'/profile', ['name' => 'c@zz0', 'email' => $customer->email])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Nome valido', $customer->fresh()->name);
        $this->patchJson($this->api.'/profile', ['name' => "  Caffe\u{0300}\u{00A0} Centro  ", 'email' => $customer->email])->assertOk();
        $this->assertSame('Caffè Centro', $customer->fresh()->name);
        $this->postJson($this->api.'/orders/checkout', [...$this->booking(), 'store_name' => 'c0c41n4'])->assertUnprocessable()->assertJsonValidationErrors('store_name');
        $this->postJson($this->api.'/orders', [...$this->booking(), 'store_name' => 'c0c41n4', 'checkout_token' => 'bypass'])->assertUnprocessable()->assertJsonValidationErrors('store_name');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inactive_destination_has_a_specific_message_and_never_issues_a_checkout_token(): void
    {
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson($this->api.'/rates/quote?city=Caserta&postal_code=81100')->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('availability_status', 'inactive')->assertJsonPath('price_cents', null)
            ->assertJsonPath('reason', 'Al momento questa zona non è attiva per le consegne, ma sarà presto disponibile.');
        foreach (['city=Case&postal_code=81100', 'city=Caserta&postal_code=80121', 'city=Aversa&postal_code=81031'] as $query) {
            $response = $this->getJson($this->api.'/rates/quote?'.$query)->assertOk()->assertJsonPath('available', false);
            $this->assertNotSame('inactive', $response->json('availability_status'));
        }
        $this->postJson($this->api.'/orders/checkout', $this->booking())->assertOk()
            ->assertJsonPath('quote.availability_status', 'inactive')->assertJsonPath('checkout_token', null)->assertJsonPath('total_cents', null);
        $this->postJson($this->api.'/orders', [...$this->booking(), 'checkout_token' => 'bypass'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_deactivating_a_rate_after_review_blocks_confirmation_atomically(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $data = $this->checkoutData($this->booking());
        $rate->update(['active' => false]);
        $this->postJson($this->api.'/orders', $data)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_quote_exposes_public_slug_and_retains_active_external_fallback(): void
    {
        $fallback = ShippingRate::factory()->create(['shipping_type' => 'external', 'is_default' => true, 'postal_code' => null, 'postal_codes' => null]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'shipping_type' => 'external', 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson($this->api.'/rates/quote?shipping_type=external&city=Aversa&postal_code=81031')->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('availability_status', 'active')->assertJsonPath('rate_slug', $fallback->slug)->assertJsonMissingPath('rate_id');
        $this->getJson($this->api.'/rates/quote?shipping_type=external&city=Caserta&postal_code=81100&weight_kg=1&max_dimension_cm=10')->assertOk()
            ->assertJsonPath('availability_status', 'inactive')->assertJsonPath('price_cents', null);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web');
        $this->getJson('/rates/quote?shipping_type=external&city=Aversa&postal_code=81031')->assertOk()
            ->assertJsonPath('rate_slug', $fallback->slug)->assertJsonMissingPath('rate_id');
    }

    public function test_inactive_street_does_not_disable_a_different_active_street(): void
    {
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'street' => 'Via Roma', 'active' => false]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'street' => 'Via Milano']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson($this->api.'/rates/quote?city=Caserta&postal_code=81100&street=Via%20Milano')->assertJsonPath('available', true);
        $this->getJson($this->api.'/rates/quote?city=Caserta&postal_code=81100&street=Via%20Roma')->assertJsonPath('availability_status', 'inactive');
        $this->getJson($this->api.'/rates/quote?city=Caserta&postal_code=81100')->assertJsonPath('availability_status', 'unverified');
    }

    public function test_unknown_staff_pages_and_missing_or_unowned_resources_render_the_same_404(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->get('/missing-launch-page')->assertNotFound()->assertSee('Error Not Found 404')
            ->assertSee('data-not-found-countdown>5', false)->assertSee(route('dashboard'));
        $this->get('/orders/'.Str::uuid())->assertNotFound()->assertSee('Error Not Found 404');
        $otherOrder = Order::factory()->create(['store_name' => 'Ordine riservato']);
        $this->actingAs(User::factory()->create())->get(route('orders.show', $otherOrder))->assertNotFound()
            ->assertSee('Error Not Found 404')->assertDontSee('Ordine riservato');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/not-found.html', $response->getContent());
        }
    }

    public function test_api_404_and_other_status_codes_keep_their_http_contract(): void
    {
        config(['app.debug' => false]);
        $this->getJson($this->api.'/orders/'.Str::uuid())->assertUnauthorized()->assertDontSee('data-not-found-countdown');
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        $this->getJson($this->api.'/orders/'.Str::uuid())->assertNotFound()->assertHeader('Content-Type', 'application/json')->assertDontSee('data-not-found-countdown');
        Route::get('/launch-status/{status}', function (int $status): never {
            abort($status);
        });
        foreach ([401, 403, 500] as $status) {
            $this->get('/launch-status/'.$status)->assertStatus($status)->assertDontSee('data-not-found-countdown');
        }
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return ['privacy_acknowledged' => true, 'beta_terms_accepted' => true, 'sender_type' => 'business', 'name' => 'Attività valida', 'email' => 'launch@example.test', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123'];
    }

    /** @return array<string, mixed> */
    private function booking(): array
    {
        return ['parcel_value' => '20', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_city' => 'Napoli', 'pickup_street_number' => '10', 'pickup_postal_code' => '80121', 'delivery_address' => 'Via Milano', 'delivery_city' => 'Caserta', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania', 'pickup_date' => now('Europe/Rome')->addDays(2)->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'documents', 'urgency' => 'standard', 'package_type' => 'standard'];
    }
}
