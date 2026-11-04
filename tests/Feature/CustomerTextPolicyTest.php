<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\Support\PortalTextPolicy;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerTextPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $api = '/api/v1/customer';

    public function test_public_policy_returns_the_same_definition_used_by_server_validation(): void
    {
        $policy = app(PortalTextPolicy::class)->definition();
        $this->getJson($this->api.'/text-policy')->assertOk()->assertExactJson($policy);
        $this->assertGuest('customer');
        if ($fixture = getenv('EA_TEXT_POLICY_FIXTURE')) {
            file_put_contents($fixture, json_encode(['policy' => $policy, 'blocked' => array_column(self::prohibitedTexts(), 0), 'allowed' => array_column(self::legitimateTexts(), 0)], JSON_THROW_ON_ERROR));
        }
    }

    /** @return array<string, array{string}> */
    public static function prohibitedTexts(): array
    {
        return [
            'italian insult' => ['cazzo'], 'uppercase' => ['Negozio di COCAINA'], 'accented' => ['CÒCÀÍNÀ'],
            'decomposed accent' => ["co\u{0301}caina"], 'english insult' => ['FUCK'], 'english drugs' => ['cocaine'], 'hashish' => ['Hashish'],
            'leetspeak' => ['c0c41n4'], 'mixed symbols' => ['c@zz0'], 'spaced letters' => ['c o c a i n a'],
            'punctuation' => ['h.a.s.h.i.s.h'], 'mixed separators' => ['c-o c_a.i!n?a'],
            'word surrounded' => ['Vendita (cocaina), online'], 'zero width' => ["coca\u{200B}ina"], 'separated phrase' => ['porco.dio'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function legitimateTexts(): array
    {
        return [
            'business suggestion' => ['Abbigliamento'], 'accented legitimate' => ['Caffè e artigianato'],
            'common substring' => ['Assistenza e articoli classici'], 'english substring' => ['Scunthorpe document services'],
            'cocktail' => ['Accessori cocktail e cucina'], 'similar characters' => ['Meridiana e ricambi'],
            'foreign letters boundary' => ['cocaina界'], 'mixed alphanumeric' => ['Documenti modello MDMA123'],
        ];
    }

    #[DataProvider('prohibitedTexts')]
    public function test_registration_returns_422_for_prohibited_business_types_without_saving(string $text): void
    {
        $this->postJson($this->api.'/auth/register', $this->registration($text))->assertUnprocessable()
            ->assertJsonValidationErrors('business_type')->assertJsonPath('errors.business_type.0', config('portal_text_policy.message'));
        $this->assertDatabaseMissing('users', ['email' => 'policy@example.test']);
        $this->assertGuest('customer');
    }

    #[DataProvider('legitimateTexts')]
    public function test_registration_accepts_legitimate_text_without_substring_false_positives(string $text): void
    {
        $this->postJson($this->api.'/auth/register', $this->registration($text))->assertCreated()->assertJsonPath('user.business_type', $text);
        $this->assertDatabaseHas('users', ['email' => 'policy@example.test', 'business_type' => $text]);
    }

    public function test_direct_profile_update_cannot_bypass_the_business_type_rule(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'business_type' => 'Fiorista']);
        $this->actingAs($customer, 'customer')->patchJson($this->api.'/profile', ['sender_type' => 'business', 'name' => $customer->name, 'email' => $customer->email, 'business_type' => 'h4sh1sh'])->assertUnprocessable()->assertJsonValidationErrors('business_type');
        $this->assertSame('Fiorista', $customer->fresh()->business_type);
    }

    public function test_checkout_create_and_update_reject_prohibited_content_before_persistence(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer');
        $data = [...$this->orderData(), 'content_description' => 'c o c a i n a'];
        $this->postJson($this->api.'/orders/checkout', $data)->assertUnprocessable()->assertJsonValidationErrors('content_description');
        $this->postJson($this->api.'/orders', [...$data, 'checkout_token' => 'bypassed-client'])->assertUnprocessable()->assertJsonValidationErrors('content_description');
        $this->assertDatabaseCount('orders', 0);

        $order = Order::factory()->create(['customer_id' => $customer->id, 'category' => 'custom', 'content_description' => 'Artigianato']);
        $this->patchJson($this->api.'/orders/'.$order->slug, [...$data, 'checkout_token' => 'bypassed-client', 'version' => $order->version])->assertUnprocessable()->assertJsonValidationErrors('content_description');
        $this->assertSame('Artigianato', $order->fresh()->content_description);
    }

    public function test_valid_custom_content_survives_checkout_create_and_detail(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($customer, 'customer');
        $response = $this->postJson($this->api.'/orders', $this->checkoutData($this->orderData()))->assertCreated()->assertJsonPath('data.category', 'custom')->assertJsonPath('data.content_description', 'Ricambi per automobile');
        $this->getJson($this->api.'/orders/'.$response->json('data.slug'))->assertOk()->assertJsonPath('data.content_description', 'Ricambi per automobile');
        $this->assertDatabaseHas('orders', ['customer_id' => $customer->id, 'category' => 'custom', 'content_description' => 'Ricambi per automobile']);
    }

    public function test_selected_content_categories_use_the_same_policy_without_changing_their_storage(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->actingAs($customer, 'customer');
        $data = [...$this->orderData(), 'category' => 'clothing', 'content_description' => ''];
        $this->postJson($this->api.'/orders', $this->checkoutData($data))->assertCreated()->assertJsonPath('data.category', 'clothing');
        $this->assertDatabaseHas('orders', ['category' => 'clothing', 'content_description' => null]);

        config(['portal_text_policy.terms' => [...config('portal_text_policy.terms'), 'abbigliamento']]);
        $this->postJson($this->api.'/orders/checkout', $data)->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->assertDatabaseCount('orders', 1);
    }

    /** @return array<string, string> */
    private function registration(string $businessType): array
    {
        return ['sender_type' => 'business', 'business_type' => $businessType, 'name' => 'Attività di prova', 'email' => 'policy@example.test', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123'];
    }

    /** @return array<string, mixed> */
    private function orderData(): array
    {
        return ['parcel_value' => '50', 'pickup_street_number' => '10', 'pickup_postal_code' => '80100', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'package_type' => 'standard', 'recipient_name' => 'Mario Rossi', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Milano', 'delivery_city' => 'Caserta', 'pickup_date' => now('Europe/Rome')->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'category' => 'custom', 'content_description' => 'Ricambi per automobile', 'urgency' => 'standard'];
    }
}
