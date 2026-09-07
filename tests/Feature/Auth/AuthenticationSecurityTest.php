<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.registration' => true]);
    }

    public function test_login_is_temporarily_blocked_after_five_failed_attempts(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Event::fake([Lockout::class]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors(['email' => __('auth.failed')]);
        }

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => __('auth.throttle', ['seconds' => 60, 'minutes' => 1]),
        ]);
        $this->assertGuest();
        Event::assertDispatched(Lockout::class);

        $this->travel(61)->seconds();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public static function invalidRegistrationData(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'invalid email' => [['email' => 'invalid-address'], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'unconfirmed password' => [['password_confirmation' => 'different-password'], 'password'],
        ];
    }

    #[DataProvider('invalidRegistrationData')]
    public function test_invalid_registration_does_not_create_an_account(array $invalid, string $field): void
    {
        $response = $this->post('/register', array_replace([
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'valid-password',
            'password_confirmation' => 'valid-password',
        ], $invalid));

        $response->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_cannot_reuse_an_existing_email(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/register', [
            'name' => 'Duplicate User',
            'email' => $user->email,
            'password' => 'valid-password',
            'password_confirmation' => 'valid-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_registration_stores_a_hashed_password_and_ignores_verification_input(): void
    {
        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'valid-password',
            'password_confirmation' => 'valid-password',
            'email_verified_at' => '2026-01-01 12:00:00',
        ]);

        $response->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $user = User::where('email', 'new@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('valid-password', $user->password));
        $this->assertNull($user->email_verified_at);
    }

    public function test_invalid_reset_token_does_not_change_password(): void
    {
        $user = User::factory()->create();
        $passwordHash = $user->password;

        $response = $this->post('/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_expired_reset_token_does_not_change_password(): void
    {
        $user = User::factory()->create();
        $passwordHash = $user->password;
        $token = Password::createToken($user);
        $this->travel(61)->minutes();

        $response = $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_password_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ];

        $this->post('/reset-password', $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));
        $passwordHash = $user->fresh()->password;

        $response = $this->post('/reset-password', array_replace($payload, [
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ]));

        $response->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertGuest();
    }
}
