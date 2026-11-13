<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetaModeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function registration(array $overrides = []): array
    {
        return [...['name' => 'Rita Rossi', 'email' => 'rita@example.test', 'sender_type' => 'private', 'password' => 'PasswordSicura123', 'password_confirmation' => 'PasswordSicura123', 'privacy_acknowledged' => true, 'beta_terms_accepted' => true], ...$overrides];
    }

    public function test_platform_exposes_beta_state_and_terms_to_the_portal(): void
    {
        $this->getJson('/api/v1/customer/platform')->assertOk()
            ->assertJsonPath('beta', true)
            ->assertJsonPath('terms_version', config('beta.terms_version'))
            ->assertJsonPath('privacy_version', config('customer_privacy.version'))
            ->assertJsonPath('terms.1.title', 'Nessun pagamento tramite l’app');
    }

    public function test_registration_requires_privacy_acknowledgement_and_beta_terms(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registration(['privacy_acknowledged' => false, 'beta_terms_accepted' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['privacy_acknowledged' => 'Conferma di aver letto l’informativa privacy.', 'beta_terms_accepted' => 'Accetta le condizioni d’uso della beta per continuare.']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_records_when_and_which_versions_were_accepted(): void
    {
        $this->freezeTime();

        $this->postJson('/api/v1/customer/auth/register', $this->registration())->assertAccepted();

        $user = User::where('email', 'rita@example.test')->sole();
        $this->assertSame(now()->toDateTimeString(), $user->privacy_acknowledged_at->toDateTimeString());
        $this->assertSame(config('customer_privacy.version'), $user->privacy_version);
        $this->assertSame(now()->toDateTimeString(), $user->terms_accepted_at->toDateTimeString());
        $this->assertSame(config('beta.terms_version'), $user->terms_version);
    }

    public function test_after_the_official_launch_only_the_privacy_acknowledgement_is_required(): void
    {
        config(['beta.enabled' => false]);

        $this->getJson('/api/v1/customer/platform')->assertJsonPath('beta', false)->assertJsonPath('terms', []);
        $this->postJson('/api/v1/customer/auth/register', $this->registration(['beta_terms_accepted' => null]))->assertAccepted();

        $user = User::where('email', 'rita@example.test')->sole();
        $this->assertNotNull($user->privacy_acknowledged_at);
        $this->assertNull($user->terms_accepted_at);
        $this->assertNull($user->terms_version);
    }

    public function test_search_engines_are_told_not_to_index_any_page(): void
    {
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/api/v1/customer/platform')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString("Disallow: /\n", file_get_contents(public_path('robots.txt')));
    }
}
