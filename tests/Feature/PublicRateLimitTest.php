<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_recovery_requests_return_404_without_dispatching_mail(): void
    {
        Notification::fake();
        $users = User::factory()->count(4)->create();

        foreach ($users as $user) {
            $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_recovery_stays_disabled_across_portals_and_after_one_hour(): void
    {
        Notification::fake();
        $this->freezeTime();

        foreach ([0, 59, 2] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertNotFound();
            $this->postJson('/api/v1/customer/auth/forgot-password', ['email' => 'unknown@example.com'])->assertNotFound();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_changing_email_cannot_restore_recovery_and_normal_pages_remain_available(): void
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/customer/auth/forgot-password', ['email' => "unknown{$attempt}@example.com"])->assertNotFound();
        }

        $this->get('/login')->assertOk();
        $this->getJson('/api/v1/customer/csrf')->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_rotating_ips_and_email_case_cannot_restore_recovery(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->postJson('/api/v1/customer/auth/forgot-password', ['email' => $attempt === 4 ? 'UNKNOWN@example.com' : 'unknown@example.com'])->assertNotFound();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
}
