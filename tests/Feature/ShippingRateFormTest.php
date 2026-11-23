<?php

namespace Tests\Feature;

use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingRateFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [...['shipping_type' => 'regional', 'city' => 'Aversa', 'area' => 'Caserta', 'postal_code' => '81031', 'postal_codes' => ['81031'], 'price' => '6', 'active' => true], ...$overrides];
    }

    public function test_the_form_shows_every_field_the_errors_can_refer_to(): void
    {
        $page = $this->get(route('rates.index'))->assertOk();

        $page->assertSee('<label class="form-label" for="rate-city">Comune', false)
            ->assertSee('id="rate-zone"', false)->assertSee('Zona / frazione (facoltativa)')
            ->assertDontSee('<input type="hidden" name="zone"', false);
    }

    public function test_validation_messages_use_the_names_shown_in_the_form(): void
    {
        $response = $this->postJson(route('rates.store'), $this->payload(['city' => '', 'price' => '']))->assertUnprocessable();

        $this->assertStringContainsString('comune', $response->json('errors.city.0'));
        $this->assertStringContainsString('Inserisci il costo della spedizione', $response->json('errors.price.0'));
        $this->assertStringNotContainsString('city', $response->json('errors.city.0'));
    }

    public function test_an_unknown_municipality_is_reported_on_the_comune_field(): void
    {
        $response = $this->postJson(route('rates.store'), $this->payload(['city' => 'Comune Inesistente Test', 'postal_code' => null, 'postal_codes' => []]))->assertUnprocessable()->assertJsonValidationErrors('city');

        $this->assertStringContainsString('campo Zona', $response->json('errors.city.0'));
        $this->assertSame(0, ShippingRate::count());
    }

    public function test_a_zone_without_its_cap_is_rejected_on_the_zone_field(): void
    {
        $this->postJson(route('rates.store'), $this->payload(['city' => 'Castel San Giorgio', 'area' => 'Salerno', 'zone' => 'Lanzara', 'postal_code' => null, 'postal_codes' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('zone');

        $this->assertSame(0, ShippingRate::count());
    }

    public function test_create_edit_toggle_and_quote_keep_the_tariff_of_the_comune(): void
    {
        $created = $this->postJson(route('rates.store'), $this->payload())->assertCreated()->assertJsonPath('data.city', 'Aversa')->assertJsonPath('data.price_cents', 600);
        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('available', true)->assertJsonPath('price_cents', 600);

        $edited = $this->postJson(route('rates.store', $created->json('data.slug')), $this->payload(['price' => '6,50']))->assertCreated()->assertJsonPath('data.price_cents', 650);
        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('price_cents', 650);
        $this->assertFalse(ShippingRate::where('slug', $created->json('data.slug'))->sole()->active);

        $disabled = $this->patchJson('/rates/'.$edited->json('data.slug').'/state', ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('available', false);
        $this->patchJson('/rates/'.$disabled->json('data.slug').'/state', ['active' => true])->assertOk()->assertJsonPath('data.active', true);
        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('available', true)->assertJsonPath('price_cents', 650);
    }

    public function test_a_hamlet_zone_with_its_cap_is_priced_from_the_zone_tariff(): void
    {
        $this->postJson(route('rates.store'), $this->payload(['city' => 'Castel San Giorgio', 'area' => 'Salerno', 'zone' => 'Lanzara', 'postal_code' => '84083', 'postal_codes' => ['84083'], 'price' => '8']))
            ->assertCreated()->assertJsonPath('data.zone', 'lanzara');

        $this->getJson('/rates/quote?city=Castel San Giorgio&postal_code=84083')->assertJsonPath('available', true)->assertJsonPath('price_cents', 800);
    }
}
