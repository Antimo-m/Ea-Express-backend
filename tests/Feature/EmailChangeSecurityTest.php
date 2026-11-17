<?php

namespace Tests\Feature;

use App\Actions\UpdateProfile;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertInvalid('email');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->app['auth']->guard($guard)->logout();

        $this->postJson($guard === 'customer' ? '/api/v1/customer/auth/forgot-password' : '/forgot-password', ['email' => 'attacker@example.test']);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'attacker@example.test']);
    }

    #[DataProvider('portals')]
    public function test_owner_cannot_change_email_even_with_the_correct_password(UserRole $role, string $guard, string $profile): void
    {
        $user = User::factory()->create(['role' => $role]);
        $email = $user->email;
        $this->actingAs($user, $guard)
            ->patchJson($profile, ['name' => 'Owner', 'email' => 'new-owner@example.test', 'current_password' => 'password'])
            ->assertInvalid(['email' => 'non può essere modificato']);
        $this->assertSame($email, $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('portals')]
    public function test_name_updates_keep_the_original_email(UserRole $role, string $guard, string $profile): void
    {
        $user = User::factory()->create(['role' => $role]);
        foreach ([['name' => 'Updated name', 'email' => strtoupper($user->email)], ['name' => 'Second name']] as $payload) {
            $response = $this->actingAs($user, $guard)->patchJson($profile, $payload);
            $guard === 'customer' ? $response->assertOk() : $response->assertRedirect('/profile');
        }
        $this->assertSame('Second name', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_staff_profile_screen_shows_the_email_as_read_only(): void
    {
        foreach ([UserRole::Rider, UserRole::Admin] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/profile')->assertOk()
                ->assertSee('id="account_email"', false)
                ->assertSee('readonly disabled', false)
                ->assertDontSee('name="email"', false)
                ->assertDontSee('per cambiare email');
        }
    }

    public function test_profile_action_ignores_email_payloads(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        app(UpdateProfile::class)->handle($user, ['name' => 'Renamed', 'email' => 'attacker@example.test']);
        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertNotSame('attacker@example.test', $user->fresh()->email);
    }

    public function test_model_refuses_email_changes_on_existing_accounts(): void
    {
        $user = User::factory()->create();
        $email = $user->email;
        try {
            $user->update(['email' => 'other@example.test']);
            $this->fail('An existing account email was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertSame($email, $user->fresh()->email);
    }
}
