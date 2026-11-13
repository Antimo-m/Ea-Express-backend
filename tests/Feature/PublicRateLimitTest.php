<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const CustomerForgot = '/api/v1/customer/auth/password/forgot';

    public function test_recovery_requests_for_one_address_are_progressively_delayed_then_blocked(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create(['role' => UserRole::Customer]);

        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertAccepted();
        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->travel(60)->seconds();
        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertAccepted();
        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertTooManyRequests()->assertHeader('Retry-After', '300');
        $this->travel(300)->seconds();
        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertAccepted();
        $this->travel(900)->seconds();
        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertTooManyRequests()->assertHeader('Retry-After', (string) (3600 - 1260));

        Notification::assertSentToTimes($user, PasswordResetLink::class, 3);
        $this->travel(3600)->seconds();
        $this->postJson(self::CustomerForgot, ['email' => $user->email])->assertAccepted();
    }

    public function test_rotating_ips_and_email_case_share_the_same_address_limit(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => UserRole::Customer, 'email' => 'cliente@example.com']);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson(self::CustomerForgot, ['email' => 'cliente@example.com'])->assertAccepted();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->postJson(self::CustomerForgot, ['email' => 'CLIENTE@example.com'])->assertTooManyRequests();

        Notification::assertSentToTimes($user, PasswordResetLink::class, 1);
    }

    public function test_one_network_cannot_spray_recovery_requests_across_many_addresses(): void
    {
        Notification::fake();
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson(self::CustomerForgot, ['email' => "unknown{$attempt}@example.com"])->assertAccepted();
        }

        $this->postJson(self::CustomerForgot, ['email' => 'unknown11@example.com'])->assertTooManyRequests();
        $this->get('/login')->assertOk();
        $this->getJson('/api/v1/customer/csrf')->assertOk();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
}
