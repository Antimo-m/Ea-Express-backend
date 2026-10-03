<?php

namespace Tests\Feature;

use App\Actions\UpdateProfile;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailChangeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function portals(): array
    {
        return [
            'rider' => [UserRole::Rider, 'web', '/profile'],
            'admin' => [UserRole::Admin, 'web', '/profile'],
            'customer' => [UserRole::Customer, 'customer', '/api/v1/customer/profile'],
        ];
    }

    #[DataProvider('portals')]
    public function test_stolen_session_cannot_redirect_recovery_to_an_attacker(UserRole $role, string $guard, string $profile): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => $role]);
        $before = $user->fresh()->getAttributes();
        $token = Password::broker('users')->createToken($user);
        $this->actingAs($user, $guard)->withSession(['auth.password_confirmed_at' => time()])
            ->patchJson($profile, ['name' => 'Attacker', 'email' => 'attacker@example.test', 'email_verified_at' => now(), 'password' => 'attacker-password', 'role' => 'admin'])
            ->assertInvalid('current_password');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->app['auth']->guard($guard)->logout();

        $this->postJson($guard === 'customer' ? '/api/v1/customer/auth/forgot-password' : '/forgot-password', ['email' => 'attacker@example.test']);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'attacker@example.test']);
    }

    #[DataProvider('portals')]
    public function test_wrong_password_cannot_change_email_and_is_not_flashed(UserRole $role, string $guard, string $profile): void
    {
        $user = User::factory()->create(['role' => $role]);
        $email = $user->email;
        $response = $this->actingAs($user, $guard)->patch($profile, ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'wrong-password']);
        $guard === 'customer' ? $response->assertInvalid('current_password') : $response->assertSessionHasErrors('current_password');
        $this->assertSame($email, $user->fresh()->email);
        $this->assertNull(session('_old_input.current_password'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('portals')]
    public function test_owner_can_change_email_and_revoke_previous_credentials(UserRole $role, string $guard, string $profile): void
    {
        $user = User::factory()->create(['role' => $role]);
        $oldEmail = $user->email;
        $oldRememberToken = $user->remember_token;
        $passwordHash = $user->password;
        Password::broker('users')->createToken($user);
        $this->actingAs($user, $guard);
        $oldSession = session()->getId();
        $response = $this->patchJson($profile, ['name' => 'Owner', 'email' => 'new-owner@example.test', 'current_password' => 'password', 'password' => 'injected-password', 'email_verified_at' => now()]);
        $guard === 'customer' ? $response->assertOk() : $response->assertRedirect('/profile');
        $this->assertSame('new-owner@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    }

    #[DataProvider('portals')]
    public function test_name_updates_do_not_require_password(UserRole $role, string $guard, string $profile): void
    {
        $user = User::factory()->create(['role' => $role]);
        $response = $this->actingAs($user, $guard)->patchJson($profile, ['name' => 'Updated name', 'email' => $user->email]);
        $guard === 'customer' ? $response->assertOk() : $response->assertRedirect('/profile');
        $this->assertSame('Updated name', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('portals')]
    public function test_password_guessing_on_email_change_is_rate_limited_across_ips(UserRole $role, string $guard, string $profile): void
    {
        $user = User::factory()->create(['role' => $role]);
        $email = $user->email;
        $this->actingAs($user, $guard);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->patchJson($profile, ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'wrong'])->assertInvalid('current_password');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->patchJson($profile, ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'wrong'])->assertTooManyRequests();
        $this->assertSame($email, $user->fresh()->email);
    }

    public function test_email_change_rechecks_password_against_locked_state(): void
    {
        $stale = User::factory()->create();
        $current = $stale->fresh();
        $current->password = Hash::make('new-password-123');
        $current->save();
        $this->actingAs($current);
        try {
            app(UpdateProfile::class)->handle($stale, ['name' => 'Attacker', 'email' => 'attacker@example.test'], 'password');
            $this->fail('Old credentials were accepted after a concurrent password change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }
        $this->assertSame($current->email, $current->fresh()->email);
        $this->assertSame($current->name, $current->fresh()->name);
    }

    public function test_password_from_another_guard_cannot_authorize_email_change(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Admin, 'password' => Hash::make('staff-password')]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'password' => Hash::make('customer-password')]);
        $email = $customer->email;
        $this->actingAs($staff, 'web')->actingAs($customer, 'customer')
            ->patchJson('/api/v1/customer/profile', ['name' => $customer->name, 'email' => 'attacker@example.test', 'current_password' => 'staff-password'])->assertInvalid('current_password');
        $this->assertSame($email, $customer->fresh()->email);
    }
}
