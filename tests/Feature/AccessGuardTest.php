<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancello e pause di sicurezza: 5 accessi negati in 2 minuti → 5 minuti, poi 15, poi 30 al massimo.
 * Contano solo i dinieghi di autorizzazione, non i normali 404, e la pausa colpisce soltanto chi insiste.
 */
class AccessGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_denied_page_is_neutral_and_does_not_reveal_roles_or_permissions(): void
    {
        $rider = User::factory()->create();

        $this->actingAs($rider)->get('/settings/users')->assertForbidden()
            ->assertSee('Pagina non disponibile')->assertSee('Torna indietro')->assertSee('Pagina iniziale')->assertDontSee('class="return"', false)
            ->assertDontSee('staff')->assertDontSee('Admin')->assertDontSee('riservat')->assertDontSee('personale');
        $this->getJson('/settings/users')->assertForbidden()->assertExactJson(['message' => 'Non è possibile completare la richiesta.']);
    }

    public function test_repeated_denials_pause_the_account_with_escalating_timeouts(): void
    {
        $rider = User::factory()->create();
        $this->actingAs($rider);
        foreach (range(1, 4) as $attempt) {
            $this->get('/settings/users')->assertForbidden();
        }
        $this->get('/dashboard')->assertOk();
        $this->get('/settings/users')->assertStatus(429)->assertHeader('Retry-After', '300')->assertSee('Accesso momentaneamente sospeso')->assertSee('5 minuti');
        $this->get('/dashboard')->assertStatus(429);
        $this->getJson('/notifications/feed')->assertStatus(429)->assertJsonPath('retry_after', fn (int $seconds): bool => $seconds > 0 && $seconds <= 300);

        $this->travel(301)->seconds();
        $this->get('/dashboard')->assertOk();
        foreach (range(1, 5) as $attempt) {
            $response = $this->get('/settings/users');
        }
        $response->assertStatus(429)->assertHeader('Retry-After', '900');

        $this->travel(901)->seconds();
        foreach (range(1, 5) as $attempt) {
            $response = $this->get('/settings/users');
        }
        $response->assertStatus(429)->assertHeader('Retry-After', '1800');

        $this->travel(1801)->seconds();
        foreach (range(1, 5) as $attempt) {
            $response = $this->get('/settings/users');
        }
        $response->assertStatus(429)->assertHeader('Retry-After', '1800');
    }

    public function test_slow_or_ordinary_navigation_errors_never_trigger_a_pause(): void
    {
        $rider = User::factory()->create();
        $this->actingAs($rider);
        foreach (range(1, 4) as $attempt) {
            $this->get('/settings/users')->assertForbidden();
        }
        $this->travel(121)->seconds();
        $this->get('/settings/users')->assertForbidden();
        foreach (range(1, 10) as $attempt) {
            $this->get('/pagina-che-non-esiste-'.$attempt)->assertNotFound();
        }
        $this->get('/dashboard')->assertOk();
    }

    public function test_pause_is_personal_and_logout_stays_available(): void
    {
        $rider = User::factory()->create();
        $colleague = User::factory()->create();
        $this->actingAs($rider);
        foreach (range(1, 5) as $attempt) {
            $this->get('/settings/users');
        }
        $this->get('/dashboard')->assertStatus(429);
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();

        $this->actingAs($colleague)->get('/dashboard')->assertOk();
    }

    public function test_customer_probing_other_orders_gets_json_pause_without_affecting_other_customers(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $foreign = Order::factory()->create(['customer_id' => $other->id]);
        $own = Order::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($customer, 'customer');
        foreach (range(1, 4) as $attempt) {
            $this->getJson('/api/v1/customer/orders/'.$foreign->slug)->assertNotFound()->assertExactJson(['message' => 'Risorsa non disponibile.']);
        }
        $this->getJson('/api/v1/customer/orders/'.$own->slug)->assertOk();
        $this->getJson('/api/v1/customer/orders/'.$foreign->slug)->assertStatus(429)->assertJsonPath('retry_after', 300)->assertJsonStructure(['message', 'retry_after']);
        $this->getJson('/api/v1/customer/orders/'.$own->slug)->assertStatus(429);

        $this->actingAs($other, 'customer')->getJson('/api/v1/customer/orders/'.$foreign->slug)->assertOk();
    }

    public function test_staff_cannot_use_customer_api_and_message_stays_neutral(): void
    {
        $rider = User::factory()->create();

        $this->actingAs($rider, 'customer')->getJson('/api/v1/customer/dashboard')->assertForbidden()->assertExactJson(['message' => 'Non è possibile completare la richiesta.']);
    }

    public function test_gate_endpoint_exists_only_for_requests_forwarded_by_nginx(): void
    {
        $this->get('/accesso-negato')->assertNotFound();
        $this->withHeader('EA-Access-Gate', 'area.ea-express.it')->get('/accesso-negato')->assertNotFound();
    }

    public function test_gate_denials_are_counted_per_network_with_neutral_page_and_json(): void
    {
        $gate = fn (string $ip) => $this->withServerVariables(['EA_ACCESS_GATE' => 'area.ea-express.it', 'EA_ACCESS_GATE_PATH' => '/settings/users', 'REMOTE_ADDR' => $ip]);

        $gate('203.0.113.10')->get('/accesso-negato')->assertForbidden()->assertSee('Pagina non disponibile')->assertDontSee('staff')->assertCookieMissing(config('session.cookie'));
        $gate('203.0.113.10')->post('/accesso-negato')->assertForbidden();
        $gate('203.0.113.10')->getJson('/accesso-negato')->assertForbidden()->assertExactJson(['message' => 'Non è possibile completare la richiesta.']);
        $gate('203.0.113.10')->get('/accesso-negato')->assertForbidden();
        $gate('203.0.113.10')->get('/accesso-negato')->assertStatus(429)->assertHeader('Retry-After', '300');
        $gate('203.0.113.10')->getJson('/accesso-negato')->assertStatus(429)->assertJsonPath('retry_after', fn (int $seconds): bool => $seconds > 290);

        $gate('198.51.100.20')->get('/accesso-negato')->assertForbidden();
    }

    public function test_inline_scripts_run_only_with_the_per_request_csp_nonce(): void
    {
        $this->app['env'] = 'production';

        $response = $this->withServerVariables(['EA_ACCESS_GATE' => 'area'])->get('/accesso-negato')->assertForbidden();
        preg_match("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $match);
        $this->assertNotEmpty($match[1] ?? null, 'La CSP di produzione deve contenere un nonce.');
        $response->assertSee('<script  nonce="'.$match[1].'" >', false);
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src', (string) $response->headers->get('Content-Security-Policy'))[0]);
    }

    public function test_gate_and_pause_pages_show_only_the_error_and_return_to_the_previous_page(): void
    {
        config(['customer.frontend_url' => 'https://app.example.test']);

        foreach (['area', 'app.example.test'] as $host) {
            $response = $this->withServerVariables(['EA_ACCESS_GATE' => $host, 'REMOTE_ADDR' => '203.0.113.30'])->get('/accesso-negato')->assertForbidden()
                ->assertSee('Non è possibile raggiungere la pagina richiesta.')->assertSee('data-unavailable-return', false)->assertSee('secondi tornerai alla pagina precedente')
                ->assertDontSee('Torna indietro')->assertDontSee('Pagina iniziale');
            $this->assertStringNotContainsString('<a ', $response->getContent());
        }
        foreach (range(1, 4) as $attempt) {
            $this->withServerVariables(['EA_ACCESS_GATE' => 'area', 'REMOTE_ADDR' => '203.0.113.31'])->get('/accesso-negato');
        }
        $this->withServerVariables(['EA_ACCESS_GATE' => 'area', 'REMOTE_ADDR' => '203.0.113.31'])->get('/accesso-negato')->assertStatus(429)
            ->assertSee('Accesso momentaneamente sospeso')->assertSee('data-unavailable-return', false)->assertDontSee('Pagina iniziale');
    }
}
