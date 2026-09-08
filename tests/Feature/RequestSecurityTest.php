<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_changing_endpoints_reject_missing_csrf_tokens(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs(User::factory()->create());
        foreach ([['POST', '/orders'], ['PATCH', '/settings'], ['POST', '/expenses'], ['POST', '/settings/users'], ['POST', '/verify-phone/send'], ['POST', '/verify-phone/confirm'], ['POST', '/conversation/'.str_repeat('a', 64)]] as [$method,$path]) {
            $this->call($method, $path)->assertStatus(419);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('order_messages', 0);
    }

    public function test_valid_csrf_token_allows_authorized_write_with_production_security_headers(): void
    {
        $user = User::factory()->create();
        $this->app['env'] = 'production';
        $this->actingAs($user)->withSession(['_token' => 'known-session-token'])->patch('/settings', ['_token' => 'known-session-token', 'notify_orders' => 0, 'notify_messages' => 1])->assertSessionHasNoErrors()->assertHeader('X-Frame-Options', 'DENY');
        $this->assertFalse($user->fresh()->notify_orders);
        $response = $this->get('/settings');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_unverified_rider_cannot_bypass_verification_on_writes_or_json_requests(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/orders', [])->assertRedirect(route('phone.notice'));
        $this->getJson('/dashboard')->assertForbidden();
        $this->patch('/profile', ['name' => 'New', 'email' => $user->email, 'phone_verified_at' => now()])->assertRedirect(route('phone.notice'));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_profile_input_cannot_elevate_role_or_replace_a_verified_phone(): void
    {
        $user = User::factory()->create();
        $phone = $user->phone;
        $this->actingAs($user)->patch('/profile', ['name' => 'Updated', 'email' => $user->email, 'role' => 'admin', 'phone' => '+393331234567', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Rider, $user->fresh()->role);
        $this->assertSame($phone, $user->fresh()->phone);
        $this->assertTrue($user->fresh()->is_active);
    }
}
