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
            'customer' => ['/api/v1/customer/auth/password/forgot', '/api/v1/customer/auth/password/reset', UserRole::Customer],
        ];
    }

    #[DataProvider('portals')]
    public function test_recovery_never_issues_tokens_or_mail_for_the_admin(string $requestPath, string $resetPath, UserRole $role): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $regular = User::factory()->create(['role' => $role]);

        $adminResponse = $this->postJson($requestPath, ['email' => $admin->email]);
        $this->travel(61)->seconds();
        $regularResponse = $this->postJson($requestPath, ['email' => 'missing-'.$regular->email]);

        $this->assertSame($regularResponse->status(), $adminResponse->status());
        $this->assertSame($regularResponse->getContent(), $adminResponse->getContent());
        Notification::assertNothingSentTo($admin);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    #[DataProvider('portals')]
    public function test_even_a_valid_token_cannot_change_admin_credentials(string $requestPath, string $resetPath, UserRole $role): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $before = $admin->fresh()->getAttributes();
        $token = Password::broker('users')->createToken($admin);

        $this->postJson($resetPath, ['email' => $admin->email, 'token' => $token, 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])->assertUnprocessable();

        $this->assertSame($before, $admin->fresh()->getAttributes());
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
