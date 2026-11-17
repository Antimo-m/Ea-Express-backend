<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_name_can_be_updated_but_email_cannot(): void
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)->patch('/profile', ['name' => 'Test User'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');
        $this->actingAs($user)->patch('/profile', ['name' => 'Other User', 'email' => 'test@example.com', 'current_password' => 'password'])
            ->assertSessionHasErrors('email');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame($email, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_rider_cannot_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        $this->assertModelExists($user);
    }

    public function test_self_service_deletion_is_denied_even_before_password_validation(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertForbidden();

        $this->assertNotNull($user->fresh());
    }
}
