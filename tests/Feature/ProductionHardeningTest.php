<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_configured_hosts_are_trusted(): void
    {
        config(['app.url' => 'https://gestionale.example.test', 'customer.frontend_url' => 'https://Portale.example.test/']);

        $patterns = app(TrustHosts::class)->hosts();

        $this->assertSame(['^gestionale\.example\.test$', '^portale\.example\.test$'], $patterns);
        $this->assertDoesNotMatchRegularExpression('{'.$patterns[0].'}i', 'evil.test');
        $this->assertDoesNotMatchRegularExpression('{'.$patterns[0].'}i', 'gestionale.example.test.evil.test');
    }

    public function test_canonical_urls_ignore_the_request_host_header(): void
    {
        config(['app.url' => 'https://gestionale.example.test']);
        CanonicalUrl::enforce();

        $this->withHeader('Host', 'evil.test')->get('/login')->assertOk()->assertDontSee('evil.test')->assertSee('https://gestionale.example.test/login', false);
    }

    public function test_unconfirmed_customers_without_data_are_pruned_after_seventy_two_hours(): void
    {
        $stale = User::factory()->unverified()->create(['role' => UserRole::Customer, 'created_at' => now()->subHours(73)]);
        $recent = User::factory()->unverified()->create(['role' => UserRole::Customer, 'created_at' => now()->subHours(10)]);
        $confirmed = User::factory()->create(['role' => UserRole::Customer, 'created_at' => now()->subDays(10)]);
        $withOrder = User::factory()->unverified()->create(['role' => UserRole::Customer, 'created_at' => now()->subDays(10)]);
        Order::factory()->create(['customer_id' => $withOrder->id]);
        $rider = User::factory()->unverified()->create(['created_at' => now()->subDays(10)]);

        $this->artisan('customers:prune-unconfirmed')->assertSuccessful();

        $this->assertModelMissing($stale);
        foreach ([$recent, $confirmed, $withOrder, $rider] as $kept) {
            $this->assertModelExists($kept);
        }
        $this->artisan('customers:prune-unconfirmed', ['--hours' => 1])->assertExitCode(2);
    }

    public function test_api_errors_do_not_reveal_internal_class_names_or_routes(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/missing-order')->assertNotFound()->assertExactJson(['message' => 'Risorsa non disponibile.']);
        $this->getJson('/api/v1/customer/does-not-exist')->assertNotFound()->assertExactJson(['message' => 'Risorsa non disponibile.']);
        $this->deleteJson('/api/v1/customer/csrf')->assertStatus(405)->assertExactJson(['message' => 'Operazione non consentita.'])->assertHeader('Allow');
    }
}
