<?php

namespace Tests\Feature;

use App\Actions\SendEmailOtp;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function portals(): array
    {
        return [
            'staff' => ['/forgot-password', '/reset-password', UserRole::Rider],
            'customer' => ['/api/v1/customer/auth/forgot-password', '/api/v1/customer/auth/reset-password', UserRole::Customer],
        ];
    }

    #[DataProvider('portals')]
    public function test_recovery_endpoints_cannot_issue_tokens_or_send_notifications(string $requestPath, string $resetPath, UserRole $role): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => $role]);

        $this->postJson($requestPath, ['email' => $user->email])->assertNotFound();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[DataProvider('portals')]
    public function test_even_previously_issued_valid_tokens_cannot_change_credentials(string $requestPath, string $resetPath, UserRole $role): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => $role, 'email_otp_hash' => Hash::make('123456'), 'email_otp_expires_at' => now()->addMinutes(10)]);
        $before = $user->fresh()->getAttributes();
        $token = Password::broker('users')->createToken($user);

        $this->postJson($resetPath, ['email' => $user->email, 'token' => $token, 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])->assertNotFound();

        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertGuest();
        $this->assertGuest('customer');
        Notification::assertNothingSent();
    }

    #[DataProvider('portals')]
    public function test_authenticated_password_change_remains_available_and_revokes_old_tokens(string $requestPath, string $resetPath, UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $guard = $role === UserRole::Customer ? 'customer' : 'web';
        $changePath = $role === UserRole::Customer ? '/api/v1/customer/password' : '/password';
        Password::broker('users')->createToken($user);

        $response = $this->actingAs($user, $guard)->putJson($changePath, ['current_password' => 'password', 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123']);
        $guard === 'customer' ? $response->assertOk() : $response->assertRedirect();

        $this->assertTrue(Hash::check('Changed-password-123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_email_change_invalidates_tokens_before_an_address_can_be_reused(): void
    {
        $user = User::factory()->create();
        $oldEmail = $user->email;
        Password::broker('users')->createToken($user);

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'new-address@example.test', 'current_password' => 'password'])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    }

    public function test_otp_delivery_uses_the_locked_email_instead_of_a_stale_user_snapshot(): void
    {
        Notification::fake();
        $stale = User::factory()->unverified()->create();
        $current = $stale->fresh();
        $current->email = 'current-recipient@example.test';
        $current->save();

        app(SendEmailOtp::class)->handle($stale);

        Notification::assertSentTo($current, RiderEmailOtp::class, function ($notification, $channels, $notifiable): bool {
            $this->assertSame('current-recipient@example.test', $notifiable->email);

            return true;
        });
    }

    public function test_authenticated_password_change_deletes_database_sessions_without_touching_another_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$user, $other] as $account) {
            DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        config(['session.driver' => 'database']);

        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'session-'.$user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-'.$other->id]);
    }

    public function test_admin_deactivation_removes_account_recovery_tokens(): void
    {
        $user = User::factory()->create();
        Password::broker('users')->createToken($user);
        $email = $user->email;

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch(route('users.update', $user), ['action' => 'deactivate'])->assertRedirect();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }
}
