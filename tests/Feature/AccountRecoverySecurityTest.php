<?php

namespace Tests\Feature;

use App\Actions\RecoverAccount;
use App\Actions\SendEmailOtp;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        return ['staff' => ['/reset-password', UserRole::Rider], 'customer' => ['/api/v1/customer/auth/reset-password', UserRole::Customer]];
    }

    private function payload(User $user, mixed $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'A-new-password-123', 'password_confirmation' => 'A-new-password-123'];
    }

    #[DataProvider('portals')]
    public function test_reset_is_single_use_and_invalidates_remember_and_otp_credentials(string $path, UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'email_otp_hash' => Hash::make('123456'), 'email_otp_expires_at' => now()->addMinutes(10)]);
        $remember = $user->remember_token;
        $token = Password::broker('users')->createToken($user);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));

        $response = $this->postJson($path, $this->payload($user, $token));
        $this->assertContains($response->status(), [200, 302]);
        $this->assertTrue(Hash::check('A-new-password-123', $user->fresh()->password));
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->postJson($path, [...$this->payload($user, $token), 'password' => 'Replay-password-123', 'password_confirmation' => 'Replay-password-123'])->assertInvalid('email');
        $this->assertTrue(Hash::check('A-new-password-123', $user->fresh()->password));
    }

    #[DataProvider('portals')]
    public function test_token_cannot_reset_another_account(string $path, UserRole $role): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $target = User::factory()->create(['role' => $role]);
        $hash = $target->password;
        $token = Password::broker('users')->createToken($owner);

        $this->postJson($path, [...$this->payload($target, $token), 'user_id' => $owner->id])->assertInvalid('email');
        $this->assertSame($hash, $target->fresh()->password);
        $this->assertTrue(Password::broker('users')->tokenExists($owner, $token));
    }

    #[DataProvider('portals')]
    public function test_expired_and_disabled_account_tokens_are_denied(string $path, UserRole $role): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => $role]);
        $hash = $user->password;
        $token = Password::broker('users')->createToken($user);
        $this->travel(61)->minutes();
        $this->postJson($path, $this->payload($user, $token))->assertInvalid('email');
        $user->is_active = false;
        $user->save();
        $token = Password::broker('users')->createToken($user);
        $this->postJson($path, $this->payload($user, $token))->assertInvalid('email');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public static function invalidTokens(): array
    {
        return ['malformed' => ['bad-token', 'email'], 'unknown' => [str_repeat('a', 64), 'email'], 'array' => [['token'], 'token'], 'oversized' => [str_repeat('b', 1000), 'token']];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_input_never_changes_credentials(mixed $token, string $field): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $this->postJson('/reset-password', $this->payload($user, $token))->assertInvalid($field);
        $this->assertSame($hash, $user->fresh()->password);
    }

    #[DataProvider('portals')]
    public function test_authenticated_password_change_revokes_old_recovery_links(string $path, UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $token = Password::broker('users')->createToken($user);
        $guard = $role === UserRole::Customer ? 'customer' : 'web';
        $change = $guard === 'customer' ? '/api/v1/customer/password' : '/password';
        $this->actingAs($user, $guard)->putJson($change, ['current_password' => 'password', 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123']);
        $this->assertTrue(Hash::check('Changed-password-123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Auth::guard($guard)->logout();

        $this->postJson($path, $this->payload($user, $token))->assertInvalid('email');
        $this->assertTrue(Hash::check('Changed-password-123', $user->fresh()->password));
    }

    public function test_email_change_invalidates_tokens_before_an_address_can_be_reused(): void
    {
        $user = User::factory()->create();
        $oldEmail = $user->email;
        $token = Password::broker('users')->createToken($user);
        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'new-address@example.test', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $replacement = User::factory()->create(['email' => $oldEmail]);
        Auth::guard('web')->logout();

        $this->postJson('/reset-password', $this->payload($replacement, $token))->assertInvalid('email');
        $this->assertTrue(Hash::check('password', $replacement->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    }

    public function test_recovery_links_ignore_host_and_forwarded_host_for_both_roles(): void
    {
        Notification::fake();
        config(['app.url' => 'https://staff.example.test', 'customer.frontend_url' => 'https://customers.example.test']);
        foreach ([UserRole::Rider, UserRole::Customer] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $path = $role === UserRole::Customer ? '/api/v1/customer/auth/forgot-password' : '/forgot-password';
            $this->withHeaders(['Host' => 'attacker.example', 'X-Forwarded-Host' => 'attacker.example'])->postJson($path, ['email' => $user->email, 'recipient' => 'attacker@example.test']);
            Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $role): bool {
                $url = $notification->toMail($user)->actionUrl;
                $this->assertSame($role === UserRole::Customer ? 'customers.example.test' : 'staff.example.test', parse_url($url, PHP_URL_HOST));
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame($user->email, $query['email']);

                return true;
            });
        }
    }

    public function test_customer_token_cannot_use_the_staff_reset_policy(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer]);
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/reset-password', [...$this->payload($user, $token), 'role' => 'admin'])->assertInvalid('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_old_customer_session_is_rejected_after_recovery(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer]);
        $oldHash = $user->password;
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/customer/auth/reset-password', $this->payload($user, $token))->assertOk();

        $this->actingAs($user->fresh(), 'customer')->withSession(['password_hash_customer' => $oldHash])
            ->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
    }

    public function test_reset_validation_is_limited_per_account_across_ips(): void
    {
        $user = User::factory()->create();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->postJson('/reset-password', $this->payload($user, str_repeat('a', 64)))->assertInvalid('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->postJson('/reset-password', $this->payload($user, str_repeat('b', 64)))->assertTooManyRequests();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
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

    public function test_reset_deletes_database_sessions_without_touching_another_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        foreach ([$user, $other] as $account) {
            DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        config(['session.driver' => 'database']);
        app(RecoverAccount::class)->reset($this->payload($user, $token), ['rider']);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
    }

    public function test_recovery_responses_do_not_disclose_account_existence_or_role(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $path = '/api/v1/customer/auth/forgot-password';
        $expected = ['message' => 'Se esiste un account cliente con questa email, riceverai il link di recupero.'];
        foreach ([$customer->email, $rider->email, 'missing@example.test'] as $email) {
            $this->postJson($path, ['email' => $email])->assertOk()->assertExactJson($expected);
        }
        Notification::assertSentTo($customer, ResetPassword::class);
        Notification::assertNotSentTo($rider, ResetPassword::class);
    }

    public function test_deleted_accounts_cannot_leave_recovery_tokens_for_reused_addresses(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $email = $user->email;
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $replacement = User::factory()->create(['email' => $email]);
        $this->post('/reset-password', $this->payload($replacement, $token))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $replacement->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }
}
