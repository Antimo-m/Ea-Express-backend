<?php

namespace Tests\Feature;

use App\Models\ShippingRate;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShippingRatePostalRevisionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [...['shipping_type' => 'regional', 'city' => 'Aversa', 'area' => 'Caserta', 'postal_code' => '81031', 'postal_codes' => ['81031'], 'price' => '8,75', 'active' => false], ...$overrides];
    }

    public function test_an_inactive_destination_without_a_cap_can_be_corrected_then_reactivated_without_losing_its_history(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'area' => 'Caserta', 'postal_code' => null, 'postal_codes' => null, 'territory_review_required' => true, 'active' => false, 'price_cents' => 875]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->postJson('/rates/'.$rate->slug, $this->payload())->assertCreated()
            ->assertJsonPath('data.postal_code', '81031')->assertJsonPath('data.postal_codes', ['81031'])
            ->assertJsonPath('data.active', false)->assertJsonPath('data.territory_review_required', false)
            ->assertJsonPath('data.price_cents', 875);
        $this->patchJson('/rates/'.$response->json('data.slug').'/state', ['active' => true])->assertOk()
            ->assertJsonPath('data.active', true)->assertJsonPath('data.territory_review_required', false);

        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('available', true)->assertJsonPath('price_cents', 875);
        $this->assertNull($rate->fresh()->postal_code);
        $this->assertFalse($rate->fresh()->active);
        $this->assertDatabaseCount('shipping_rates', 3);
    }

    public function test_a_verified_province_suffix_can_be_used_to_repair_an_inactive_destination(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa (CE)', 'city_key' => 'aversa (ce)', 'postal_code' => null, 'territory_review_required' => true, 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/rates/'.$rate->slug, $this->payload(['city' => 'Aversa (CE)', 'active' => true]))->assertCreated()
            ->assertJsonPath('data.city', 'Aversa')->assertJsonPath('data.city_key', 'aversa')
            ->assertJsonPath('data.postal_code', '81031')->assertJsonPath('data.territory_review_required', false);

        $this->assertSame('Aversa (CE)', $rate->fresh()->city);
    }

    public function test_updating_the_cap_list_does_not_inherit_the_old_single_cap(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'postal_code' => '80121', 'postal_codes' => ['80121']]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $data = $this->payload(['city' => 'Napoli', 'area' => 'Napoli', 'postal_codes' => ['80122', '80123'], 'active' => true]);
        unset($data['postal_code']);

        $this->postJson('/rates/'.$rate->slug, $data)->assertCreated()->assertJsonPath('data.postal_code', null)
            ->assertJsonPath('data.postal_codes', ['80122', '80123']);

        $this->assertSame('80121', $rate->fresh()->postal_code);
    }

    public function test_a_homonymous_destination_is_identified_by_verified_caps_and_can_be_reactivated(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Samone', 'city_key' => 'samone', 'postal_code' => null, 'territory_review_required' => true, 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $corrected = $this->postJson('/rates/'.$rate->slug, $this->payload(['city' => 'Samone', 'area' => 'Torino', 'postal_code' => '10010', 'postal_codes' => ['10010']]))
            ->assertCreated()->assertJsonPath('data.territory_review_required', false)->json('data.slug');
        $this->patchJson('/rates/'.$corrected.'/state', ['active' => true])->assertOk()->assertJsonPath('data.postal_code', '10010');

        $this->getJson('/rates/quote?city=Samone&province=TO&postal_code=10010')->assertJsonPath('available', true);
        $this->assertNull($rate->fresh()->postal_code);
    }

    public function test_updating_only_the_single_cap_replaces_the_existing_list(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Napoli', 'city_key' => 'napoli', 'postal_code' => '80121', 'postal_codes' => ['80121']]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $data = $this->payload(['city' => 'Napoli', 'area' => 'Napoli', 'postal_code' => '80122', 'active' => true]);
        unset($data['postal_codes']);

        $this->postJson('/rates/'.$rate->slug, $data)->assertCreated()->assertJsonPath('data.postal_code', '80122')
            ->assertJsonPath('data.postal_codes', ['80122']);
    }

    public function test_reactivation_rechecks_a_destination_still_marked_for_territory_review(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'postal_codes' => ['81031'], 'territory_review_required' => true, 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->patchJson('/rates/'.$rate->slug.'/state', ['active' => true])->assertOk()->assertJsonPath('data.territory_review_required', false);

        $this->getJson('/rates/quote?city=Aversa&postal_code=81031')->assertJsonPath('available', true);
    }

    public function test_an_active_rate_with_an_overlapping_cap_prevents_reactivation_and_preserves_both_records(): void
    {
        $existing = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'postal_codes' => ['81031']]);
        $inactive = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => null, 'active' => false, 'territory_review_required' => true]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/rates/'.$inactive->slug, $this->payload(['active' => true]))->assertUnprocessable()
            ->assertJsonValidationErrors('city')->assertJsonPath('errors.city.0', 'Esiste già una tariffa attiva con questi criteri. Modifica quella esistente.');

        $this->assertDatabaseCount('shipping_rates', 2);
        $this->assertTrue($existing->fresh()->active);
        $this->assertFalse($inactive->fresh()->active);
        $this->assertNull($inactive->fresh()->postal_code);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPostalCodes(): array
    {
        return [
            'too short' => [['postal_code' => '8103', 'postal_codes' => ['8103']], 'postal_code'],
            'letters' => [['postal_code' => 'abcde', 'postal_codes' => ['abcde']], 'postal_code'],
            'foreign cap' => [['postal_code' => '81100', 'postal_codes' => ['81100']], 'postal_code'],
            'duplicate list entries' => [['postal_code' => null, 'postal_codes' => ['81031', '81031']], 'postal_codes.0'],
            'conflicting scalar and list' => [['city' => 'Napoli', 'postal_code' => '80121', 'postal_codes' => ['80122']], 'postal_codes'],
            'unknown city' => [['city' => 'Località inesistente'], 'city'],
        ];
    }

    #[DataProvider('invalidPostalCodes')]
    public function test_invalid_or_conflicting_postal_codes_do_not_create_a_version(array $changes, string $field): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => null, 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/rates/'.$rate->slug, $this->payload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('shipping_rates', 1);
        $this->assertNull($rate->fresh()->postal_code);
    }

    public function test_rate_updates_reject_numeric_identifiers_and_riders(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/rates/'.$rate->id, $this->payload())->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Rider]))->postJson('/rates/'.$rate->slug, $this->payload())->assertForbidden();

        $this->assertDatabaseCount('shipping_rates', 1);
    }

    public function test_an_abbreviated_legacy_city_is_recovered_only_with_a_matching_verified_cap(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Casalnuovo', 'city_key' => 'casalnuovo', 'area' => 'Napoli / Nola', 'postal_code' => null, 'postal_codes' => null, 'territory_review_required' => true, 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/rates/'.$rate->slug, $this->payload(['city' => 'Casalnuovo', 'area' => 'Napoli / Nola', 'postal_code' => '80016', 'postal_codes' => ['80016']]))
            ->assertUnprocessable()->assertJsonValidationErrors('city')
            ->assertJsonFragment(['city' => ['Comune non identificato con certezza. Usa il nome ufficiale e, per i comuni omonimi, aggiungi la sigla della provincia tra parentesi (es. Samone (TO)). Se è una frazione, inserisci il comune di appartenenza e scrivi la frazione nel campo Zona (es. comune «Castel San Giorgio», zona «Lanzara»). Comuni corrispondenti: Casalnuovo di Napoli (NA, CAP 80013); Casalnuovo Monterotaro (FG, CAP 71033). Il CAP 80016 appartiene a Marano di Napoli (NA).']]);
        $this->postJson('/rates/'.$rate->slug, $this->payload(['city' => 'Casalnuovo', 'area' => 'Napoli / Nola', 'postal_code' => '', 'postal_codes' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('city');

        $corrected = $this->postJson('/rates/'.$rate->slug, $this->payload(['city' => 'Casalnuovo', 'area' => 'Napoli / Nola', 'postal_code' => '80013', 'postal_codes' => ['80013']]))
            ->assertCreated()->assertJsonPath('data.city', 'Casalnuovo di Napoli')->assertJsonPath('data.postal_code', '80013')->assertJsonPath('data.territory_review_required', false)->json('data.slug');
        $this->patchJson('/rates/'.$corrected.'/state', ['active' => true])->assertOk();
        $this->getJson('/rates/quote?city=Casalnuovo di Napoli&province=NA&postal_code=80013')->assertJsonPath('available', true);
        $this->getJson('/rates/quote?city=Casalnuovo di Napoli&province=NA&postal_code=80016')->assertJsonPath('available', false);
        $this->assertNull($rate->fresh()->postal_code);
    }

    public function test_a_hamlet_cannot_be_activated_as_a_municipality_and_is_rated_as_a_zone_of_its_municipality(): void
    {
        $hamlet = ShippingRate::factory()->create(['city' => 'Lanzara', 'city_key' => 'lanzara', 'area' => 'Salerno', 'postal_code' => null, 'postal_codes' => null, 'territory_review_required' => true, 'active' => false, 'price_cents' => 800]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $blocked = $this->patchJson('/rates/'.$hamlet->slug.'/state', ['active' => true])->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->assertStringContainsString('Se è una frazione, inserisci il comune di appartenenza', $blocked->json('errors.city.0'));
        $this->assertFalse($hamlet->fresh()->active);

        $zone = $this->postJson('/rates', $this->payload(['city' => 'Castel San Giorgio', 'zone' => 'Lanzara', 'area' => 'Salerno', 'postal_code' => '84083', 'postal_codes' => ['84083'], 'price' => '8', 'active' => true]))
            ->assertCreated()->assertJsonPath('data.city', 'Castel San Giorgio')->assertJsonPath('data.zone', 'lanzara')->assertJsonPath('data.active', true);
        $this->getJson('/rates/quote?city=Castel San Giorgio&postal_code=84083&zone=Lanzara')->assertJsonPath('available', true)->assertJsonPath('price_cents', 800);
        $this->assertNotNull($zone->json('data.slug'));
    }

    public function test_a_cap_alone_never_renames_a_destination_to_an_unrelated_municipality(): void
    {
        $rate = ShippingRate::factory()->create(['city' => 'Camposanto', 'city_key' => 'camposanto', 'area' => 'Napoli / Nola', 'postal_code' => null, 'postal_codes' => null, 'territory_review_required' => true, 'active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson('/rates/'.$rate->slug, $this->payload(['city' => 'Camposanto', 'area' => 'Napoli / Nola', 'postal_code' => '80030', 'postal_codes' => ['80030']]))
            ->assertUnprocessable();
        $this->assertSame(1, ShippingRate::count());
    }
}
