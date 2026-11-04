<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RecipientIncident;
use App\Models\RecipientRiskProfile;
use App\Models\User;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RecipientDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_links_to_person_without_exposing_phone_address_or_order_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $incident = RecipientIncident::factory()->create(['recipient' => ['recipient_name' => 'Destinatario Test Nominativo Esteso', 'recipient_phone' => '+390000000000', 'delivery_address' => 'Via Test Dimostrativa del Punto di Ritiro 145', 'delivery_city' => 'Napoli']]);

        $this->actingAs($admin)->get(route('recipient-incidents.index'))
            ->assertSee('Destinatario Test Nominativo Esteso')->assertSee('Napoli')
            ->assertSee(route('recipient-incidents.show', $incident->profile), false)
            ->assertDontSee('+390000000000')->assertDontSee('Via Test Dimostrativa del Punto di Ritiro 145')
            ->assertDontSee('Apri ordine')->assertDontSee('Storico rettifiche');
    }

    public function test_detail_paginates_only_the_persons_real_history_including_removed_incidents(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = RecipientRiskProfile::factory()->create();
        $order = Order::factory()->create(['store_name' => 'Azienda Test Distribuzione Metropolitana']);
        $latest = RecipientIncident::factory()->create([
            'recipient_risk_profile_id' => $profile->id, 'order_id' => $order->id, 'correction_reason' => 'Telefono confermato dal negozio',
            'recipient' => ['recipient_name' => 'Destinatario Test '.str_repeat('Nominativo ', 10), 'recipient_phone' => '+393331234567', 'delivery_address' => str_repeat('IndirizzoMoltoLungo', 9), 'delivery_street_number' => '145', 'delivery_postal_code' => '80100', 'delivery_city' => 'Napoli', 'delivery_province' => 'Napoli'],
        ]);
        RecipientIncident::factory()->count(20)->create(['recipient_risk_profile_id' => $profile->id, 'occurred_at' => now()->subDay(), 'dismissed_at' => now(), 'correction_reason' => 'Segnalazione non confermata']);
        $other = RecipientIncident::factory()->create();

        $response = $this->actingAs($admin)->get(route('recipient-incidents.show', $profile));

        $response->assertSee($latest->recipient['recipient_phone'])->assertSee($order->store_name)
            ->assertSee('class="recipient-detail-page"', false)
            ->assertSee('Cronologia ordini non ritirati')->assertSee('Telefono confermato dal negozio')
            ->assertSee('Segnalazione rimossa')->assertSee('Destinatario assente / mancata consegna')
            ->assertDontSee($other->order->reference)
            ->assertViewHas('incidents', fn ($incidents): bool => $incidents->total() === 21 && $incidents->count() === 20);
        $this->get(route('recipient-incidents.show', ['profile' => $profile, 'page' => 2]))
            ->assertViewHas('incidents', fn ($incidents): bool => $incidents->count() === 1);

        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/recipient-detail-typography.html', $response->getContent());
        }
    }

    public function test_unauthenticated_detail_redirects_to_login(): void
    {
        $incident = RecipientIncident::factory()->create();

        $this->get(route('recipient-incidents.show', $incident->profile))->assertRedirect(route('login'));
    }

    public function test_correction_dialog_keeps_only_save_and_explicitly_confirmed_removal_actions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $incident = RecipientIncident::factory()->create();

        $response = $this->actingAs($admin)->get(route('recipient-incidents.show', $incident->profile));

        $response->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $dialogs = $xpath->query('//dialog[contains(@id, "correction-")]');
        $this->assertSame(2, $dialogs->length);
        foreach ($dialogs as $dialog) {
            $this->assertSame(2, $xpath->query('.//footer/button', $dialog)->length);
            $this->assertSame(0, $xpath->query('.//footer/button[@data-dialog-close]', $dialog)->length);
            $this->assertSame(1, $xpath->query('.//footer/button[@type="submit"][@name="action"][@value="correct"][contains(@class, "action-confirm")]', $dialog)->length);
            $this->assertSame(1, $xpath->query('.//footer/button[@type="submit"][@name="action"][@value="dismiss"][@data-confirm="Rimuovi segnalazione"][contains(@class, "action-delete")]', $dialog)->length);
            $this->assertSame(1, $xpath->query('.//header/button[@data-dialog-close]', $dialog)->length);
        }
    }

    #[TestWith([UserRole::Rider])]
    #[TestWith([UserRole::Customer])]
    public function test_non_admin_cannot_read_personal_data(UserRole $role): void
    {
        $incident = RecipientIncident::factory()->create();
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('recipient-incidents.show', $incident->profile))->assertForbidden();
    }

    public function test_missing_invalid_and_empty_profiles_return_not_found(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $emptyProfile = RecipientRiskProfile::factory()->create();
        $this->actingAs($admin);

        $this->get('/recipient-incidents/invalid')->assertNotFound();
        $this->get('/recipient-incidents/999999')->assertNotFound();
        $this->get(route('recipient-incidents.show', $emptyProfile))->assertNotFound();
    }

    public function test_detail_escapes_untrusted_person_and_correction_text(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $payload = '<script>alert("recipient")</script>';
        $incident = RecipientIncident::factory()->create(['recipient' => ['recipient_name' => $payload, 'recipient_phone' => '+390000000000', 'delivery_address' => $payload, 'delivery_city' => 'Napoli'], 'correction_reason' => $payload]);

        $this->actingAs($admin)->get(route('recipient-incidents.show', $incident->profile))
            ->assertSee($payload)->assertDontSee($payload, false);
    }

    public function test_dismiss_returns_to_reliable_person_and_preserves_order_and_audit(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create();
        app(RecipientRisk::class)->record($order, $admin);
        $incident = RecipientIncident::sole();
        $before = $order->fresh()->toArray();

        $this->actingAs($admin)->patch(route('recipient-incidents.update', $incident), ['action' => 'dismiss', 'version' => $incident->version, 'correction_reason' => 'Segnalazione non confermata'])
            ->assertSessionHasNoErrors()->assertRedirect(route('recipient-incidents.show', $incident->profile));

        $this->assertNotNull($incident->fresh()->dismissed_at);
        $this->assertSame($before, $order->fresh()->toArray());
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'recipient_incidents', 'entity_id' => $incident->id, 'action' => 'recipient_incident.dismiss']);
        $this->get(route('recipient-incidents.show', $incident->profile))->assertSee('Affidabile')->assertSee('Segnalazione rimossa');
    }

    public function test_correction_updates_only_incident_data_and_returns_to_current_profile(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create();
        app(RecipientRisk::class)->record($order, $admin);
        $incident = RecipientIncident::sole();
        $before = $order->fresh()->toArray();
        $data = [...$incident->recipient, 'recipient_name' => 'Destinatario Test Nominativo Esteso', 'recipient_phone' => '+390000000000', 'delivery_postal_code' => '80100', 'delivery_street_number' => '145', 'action' => 'correct', 'version' => $incident->version, 'correction_reason' => 'Dati verificati con il destinatario'];

        $response = $this->actingAs($admin)->patch(route('recipient-incidents.update', $incident), $data);

        $incident->refresh();
        $response->assertSessionHasNoErrors()->assertRedirect(route('recipient-incidents.show', $incident->profile));
        $this->assertSame('Destinatario Test Nominativo Esteso', $incident->recipient['recipient_name']);
        $this->assertSame($before, $order->fresh()->toArray());
        $this->assertDatabaseHas('economic_audits', ['entity_type' => 'recipient_incidents', 'entity_id' => $incident->id, 'action' => 'recipient_incident.correct']);
        $this->get(route('recipient-incidents.show', $incident->profile))->assertSee('Destinatario Test Nominativo Esteso');
    }
}
