<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RecipientIncident;
use App\Models\ShippingRate;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\OrderStatus;
use App\Rules\PlainText;
use App\Support\PortalTextPolicy;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TestingRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function offensiveTexts(): array
    {
        return [
            'reported word' => ['stronzo'], 'uppercase' => ['STRONZO'], 'derived word' => ['Stronzetto'],
            'digits' => ['str0nz0'], 'repeated letters' => ['strooonzo'], 'spaced letters' => ['s t r o n z o'],
            'full width' => ['Ｓｔｒｏｎｚｏ'], 'blasphemy joined' => ['porcodio'], 'blasphemy separated' => ['Dio-cane'],
            'threat' => ['Ti ammazzo'], 'english' => ['fucking shop'], 'symbols' => ['m€rd@'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function legitimateTexts(): array
    {
        return [
            'surname containing a root' => ['Cazzola Ricambi'], 'lombard surname' => ['Ferramenta Cazzaniga'],
            'town and surname' => ['Hotel Troia'], 'pasta sauce' => ['Spaghetti alla puttanesca'],
            'accented business' => ['Caffè & Pasticceria Né'], 'apostrophe' => ['L’Angolo dei Sapori'],
            'unicode name' => ['Zoë Dvořák'], 'long text' => [str_repeat('Consegne rapide ', 8)],
        ];
    }

    #[DataProvider('offensiveTexts')]
    public function test_policy_blocks_offensive_variants(string $text): void
    {
        $this->assertFalse(app(PortalTextPolicy::class)->allows($text));
    }

    #[DataProvider('legitimateTexts')]
    public function test_policy_accepts_legitimate_names(string $text): void
    {
        $this->assertTrue(app(PortalTextPolicy::class)->allows($text));
    }

    public function test_staff_order_rejects_offensive_identity_fields_but_accepts_unusual_addresses(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ShippingRate::factory()->create(['city' => 'Aversa', 'city_key' => 'aversa', 'postal_code' => '81031', 'price_cents' => 500]);
        $this->actingAs($admin);
        foreach (['business_type' => 'stronzo', 'store_name' => 'Negozio Stronzo', 'recipient_name' => 'Porco Dio', 'content_description' => 'c0ca1na', 'notes' => "Citofonare\nti ammazzo"] as $field => $value) {
            $payload = [...$this->staffPayload(), $field => $value, ...($field === 'content_description' ? ['category' => 'custom'] : [])];
            $this->post('/orders', $payload)->assertRedirect('/orders/create')->assertSessionHasErrors([$field => config('portal_text_policy.message')]);
        }
        $this->post('/orders', [...$this->staffPayload(), 'pickup_address' => 'Via Cazzo di Bove'])->assertOk()->assertViewIs('orders.checkout');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_control_characters_and_markup_are_rejected_without_rewriting_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $this->post('/orders', [...$this->staffPayload(), 'recipient_name' => '<script>alert(1)</script>'])->assertSessionHasErrors(['recipient_name' => PlainText::Message]);
        $this->post('/orders', [...$this->staffPayload(), 'pickup_address' => "Via Roma\u{202E}"])->assertSessionHasErrors(['pickup_address' => PlainText::Message]);
        $this->post('/orders', [...$this->staffPayload(), 'store_name' => "Negozio\x07"])->assertSessionHasErrors('store_name');
        $this->patch('/profile', ['name' => 'Stronzo'])->assertSessionHasErrors('name');
        $this->post('/settings/users', ['name' => 'Rider Stronzo', 'email' => 'rider-policy@example.test'])->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('users', ['email' => 'rider-policy@example.test']);
    }

    public function test_private_customer_names_are_filtered_on_registration_and_profile(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['privacy_acknowledged' => true, 'beta_terms_accepted' => true, 'sender_type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Stronzo', 'email' => 'private@example.test', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123'])
            ->assertUnprocessable()->assertJsonValidationErrors('last_name');
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private', 'name' => 'Mario Rossi']);
        $this->actingAs($customer, 'customer')->patchJson('/api/v1/customer/profile', ['sender_type' => 'private', 'first_name' => 'Vaffanculo', 'last_name' => 'Rossi'])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->assertSame('Mario Rossi', $customer->fresh()->name);
    }

    public function test_passwords_are_not_content_filtered_and_are_stored_hashed(): void
    {
        $password = 'Stronzo-Sicuro-2026!';
        $this->postJson('/api/v1/customer/auth/register', ['privacy_acknowledged' => true, 'beta_terms_accepted' => true, 'sender_type' => 'business', 'business_type' => 'Fiorista', 'name' => 'Fiori di Maria', 'email' => 'hash@example.test', 'password' => $password, 'password_confirmation' => $password])->assertAccepted();
        $stored = User::where('email', 'hash@example.test')->value('password');
        $this->assertNotSame($password, $stored);
        $this->assertTrue(Hash::check($password, $stored));
        $this->postJson('/api/v1/customer/auth/register', ['privacy_acknowledged' => true, 'beta_terms_accepted' => true, 'sender_type' => 'business', 'business_type' => 'Fiorista', 'name' => 'Fiori di Anna', 'email' => 'short@example.test', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    /** @return array<string, array{OrderStatus, bool}> */
    public static function reassignmentStates(): array
    {
        return [
            'accepted' => [OrderStatus::Accepted, true], 'rider arriving' => [OrderStatus::RiderArriving, true],
            'picked up' => [OrderStatus::PickedUp, false], 'in transit' => [OrderStatus::InTransit, false],
            'out for delivery' => [OrderStatus::OutForDelivery, false], 'delivery attempted' => [OrderStatus::DeliveryAttempted, false],
        ];
    }

    #[DataProvider('reassignmentStates')]
    public function test_rider_can_be_changed_only_until_rider_arriving(OrderStatus $status, bool $allowed): void
    {
        [$admin, $rider, $other] = [User::factory()->create(['role' => UserRole::Admin]), User::factory()->create(), User::factory()->create()];
        $order = Order::factory()->create(['status' => $status, 'rider_id' => $rider->id]);
        $response = $this->actingAs($admin)->patchJson(route('orders.rider', $order), ['rider_id' => $other->slug, 'version' => 1]);
        $allowed ? $response->assertOk() : $response->assertUnprocessable()->assertJsonValidationErrors(['rider_id' => 'già stato ritirato']);
        $this->assertSame($allowed ? $other->id : $rider->id, $order->fresh()->rider_id);
        $page = $this->get(route('orders.show', $order))->assertOk();
        $allowed ? $page->assertSee('id="reassign-rider"', false) : $page->assertDontSee('id="reassign-rider"', false)->assertSee(Order::RiderLockedMessage);
    }

    public function test_reassignment_stays_blocked_after_pickup_even_when_the_order_is_rescheduled(): void
    {
        [$admin, $rider, $other] = [User::factory()->create(['role' => UserRole::Admin]), User::factory()->create(), User::factory()->create()];
        $order = Order::factory()->create(['status' => OrderStatus::Rescheduled, 'rider_id' => $rider->id]);
        $order->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $rider->id]);
        $this->actingAs($admin)->patchJson(route('orders.rider', $order), ['rider_id' => $other->slug, 'version' => 1])->assertUnprocessable();
        $this->assertSame($rider->id, $order->fresh()->rider_id);
    }

    public function test_transport_step_no_longer_skips_to_delivery_and_offers_problem_options(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::PickedUp, 'rider_id' => $admin->id]);
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Avvia il trasporto')->assertSee('Segnala un problema')->assertSee('Destinatario non presente')->assertSee('Annulla ordine')
            ->assertDontSee('Inizia la consegna')->assertDontSee('Origine del prezzo');
        $this->patchJson(route('orders.update', $order), ['status' => 'out_for_delivery', 'version' => 1])->assertUnprocessable();
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
    }

    public function test_recipient_absent_report_marks_recipient_once_and_keeps_history(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::InTransit, 'rider_id' => $rider->id, 'recipient_phone' => '+390811234567']);
        $this->actingAs($rider)->patchJson(route('orders.update', $order), ['status' => 'delivery_attempted', 'version' => 1, 'note' => 'Destinatario non presente all’indirizzo di consegna.'])->assertOk();
        $this->patchJson(route('orders.update', $order), ['status' => 'delivery_attempted', 'version' => 1, 'note' => 'Doppio invio'])->assertUnprocessable();
        $this->assertSame(OrderStatus::DeliveryAttempted, $order->fresh()->status);
        $this->assertSame(1, RecipientIncident::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'status' => 'delivery_attempted', 'user_id' => $rider->id]);
    }

    public function test_cancellation_during_transport_requires_admin_and_a_reason(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::PickedUp, 'rider_id' => $rider->id]);
        $this->actingAs($rider)->get(route('orders.show', $order))->assertOk()->assertDontSee('value="cancelled"', false);
        $this->patchJson(route('orders.update', $order), ['status' => 'cancelled', 'version' => 1, 'note' => 'Il cliente ha rinunciato'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->patchJson(route('orders.update', $order), ['status' => 'cancelled', 'version' => 1])->assertJsonValidationErrors('note');
        $this->patchJson(route('orders.update', $order), ['status' => 'cancelled', 'version' => 1, 'note' => 'no'])->assertJsonValidationErrors('note');
        $this->patchJson(route('orders.update', $order), ['status' => 'cancelled', 'version' => 1, 'note' => 'Merce non conforme'])->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'status' => 'cancelled', 'note' => 'Merce non conforme', 'user_id' => $admin->id]);
        $this->assertDatabaseCount('recipient_incidents', 0);
    }

    public function test_password_reset_email_has_no_copy_paste_fallback_link(): void
    {
        $user = User::factory()->create();
        $html = (string) (new PasswordResetLink('token-value'))->toMail($user)->render();
        $this->assertStringContainsString('Scegli una nuova password', $html);
        $this->assertStringContainsString('token-value', $html);
        $this->assertStringNotContainsString('copia e incolla', $html);
        $this->assertSame(1, substr_count($html, 'token-value'));
    }

    public function test_login_toast_and_admin_recovery_copy(): void
    {
        $this->get('/forgot-password')->assertOk()->assertDontSee('account Admin');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->withSession(['status' => 'signed-in'])->get('/dashboard')->assertOk()
            ->assertSee('app-flash-signed-in', false)->assertSee('Accesso eseguito');
    }

    public function test_privacy_endpoint_exposes_configurable_legal_fields_without_inventing_them(): void
    {
        config(['customer_privacy.controller_vat' => null, 'customer_privacy.controller_name' => null]);
        $this->getJson('/api/v1/customer/privacy')->assertOk()
            ->assertJsonPath('controller.vat', null)->assertJsonPath('controller.name', null)->assertJsonPath('reviewed', false)
            ->assertJsonPath('updated_at', config('customer_privacy.version'))->assertJsonStructure(['security_check', 'security_log_days', 'cookies']);
    }

    /** @return array<string, mixed> */
    private function staffPayload(): array
    {
        return ['store_name' => 'Mittente', 'sender_type' => 'business', 'business_type' => 'Fiorista', 'recipient_name' => 'Destinatario', 'recipient_phone' => '+393331234567', 'pickup_address' => 'Via Roma', 'pickup_street_number' => '1', 'pickup_postal_code' => '81031', 'pickup_city' => 'Aversa', 'delivery_address' => 'Via Roma', 'delivery_street_number' => '2', 'delivery_postal_code' => '81031', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania', 'delivery_city' => 'Aversa', 'pickup_date' => now()->addDay()->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'parcel_value' => '50', 'category' => 'documents', 'package_type' => 'standard', 'urgency' => 'standard'];
    }
}
