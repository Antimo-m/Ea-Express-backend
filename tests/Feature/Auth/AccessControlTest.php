<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedRoutes(): array
    {
        return [
            'dashboard' => ['GET', '/dashboard'],
            'profile' => ['GET', '/profile'],
            'profile update' => ['PATCH', '/profile'],
            'profile deletion' => ['DELETE', '/profile'],
            'password update' => ['PUT', '/password'],
            'logout' => ['POST', '/logout'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $uri): void
    {
        $response = $this->call($method, $uri);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_authenticated_users_can_open_dashboard_without_email_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()->assertViewIs('dashboard.index');
    }

    public function test_dashboard_escapes_the_authenticated_users_name(): void
    {
        $user = User::factory()->create(['name' => '<script>alert("xss")</script>']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSee($user->name)->assertDontSee($user->name, escape: false);
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_profile_update_cannot_modify_another_user_or_verify_email(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->create(['name' => 'Other User']);

        $response = $this->actingAs($user)->patch('/profile', [
            'id' => $other->id,
            'name' => 'Updated Name',
            'email' => $user->email,
            'email_verified_at' => '2026-01-01 12:00:00',
        ]);

        $response->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame('Updated Name', $user->fresh()->name);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('Other User', $other->fresh()->name);
    }
}
