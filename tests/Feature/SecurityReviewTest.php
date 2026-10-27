<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_assign_a_staff_role(): void
    {
        $this->post('/register', ['name' => 'Cliente', 'email' => 'client@example.com', 'password' => 'valid-password', 'password_confirmation' => 'valid-password', 'role' => 'admin'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_disabled_password_recovery_returns_404_for_known_and_unknown_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertNotFound();
        }
        Notification::assertNothingSent();
    }

    public function test_repeated_password_recovery_requests_remain_unavailable(): void
    {
        Notification::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertNotFound();
        }
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertNotFound();
        Notification::assertNothingSent();
    }

    public function test_sensitive_pages_send_security_and_cache_headers(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    }
}
