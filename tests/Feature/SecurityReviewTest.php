<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\Support\CanonicalUrl;
use App\Support\PasswordRecovery;
use App\UserRole;
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

    public function test_password_recovery_answers_identically_for_known_and_unknown_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'unknown@example.com']);

        $known->assertRedirect()->assertSessionHas('status', PasswordRecovery::RequestedMessage);
        $unknown->assertRedirect()->assertSessionHas('status', PasswordRecovery::RequestedMessage);
        Notification::assertSentToTimes($user, PasswordResetLink::class, 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_reset_link_points_to_the_configured_application_and_expires_in_fifteen_minutes(): void
    {
        Notification::fake();
        config(['app.url' => 'https://gestionale.example.test', 'customer.frontend_url' => 'https://portale.example.test']);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->withHeader('Host', 'evil.test')->post('/forgot-password', ['email' => $rider->email]);
        $this->withHeader('Host', 'evil.test')->postJson('/api/v1/customer/auth/password/forgot', ['email' => $customer->email])->assertAccepted();

        Notification::assertSentTo($rider, PasswordResetLink::class, function (PasswordResetLink $notification) use ($rider): bool {
            CanonicalUrl::enforce();
            $url = $notification->url($rider);
            $this->assertStringStartsWith('https://gestionale.example.test/reset-password/', $url);
            $this->assertStringContainsString('15 minuti', implode(' ', [...$notification->toMail($rider)->introLines, ...$notification->toMail($rider)->outroLines]));

            return true;
        });
        Notification::assertSentTo($customer, PasswordResetLink::class, function (PasswordResetLink $notification) use ($customer): bool {
            $this->assertStringStartsWith('https://portale.example.test/reset-password#token=', $notification->url($customer));

            return true;
        });
    }

    public function test_sensitive_pages_send_security_and_cache_headers(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    }
}
