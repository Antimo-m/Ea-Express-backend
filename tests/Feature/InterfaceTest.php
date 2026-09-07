<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_localized_and_has_a_page_title(): void
    {
        $this->get('/login')
            ->assertSee('<html lang="it">', false)
            ->assertSee('<title>Accedi · EA-Express</title>', false)
            ->assertSee('Password dimenticata?');
    }

    public function test_login_validation_is_rendered_in_italian_without_repopulating_the_password(): void
    {
        $this->followingRedirects()->from('/login')->post('/login', [
            'email' => 'not-an-email',
            'password' => 'NeverEchoThisPassword',
        ])->assertSee('Inserisci un indirizzo email valido.')
            ->assertSee('aria-describedby="email-error"', false)
            ->assertDontSee('NeverEchoThisPassword');
    }

    public function test_profile_password_errors_remain_linked_to_the_correct_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->followingRedirects()->from('/profile')->put('/password', [
            'current_password' => 'incorrect',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSee('La password attuale non è corretta.')
            ->assertSee('aria-describedby="current-password-error"', false);
    }
}
