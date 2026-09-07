<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_real_requests_and_creation_action(): void
    {
        $user = User::factory()->create(['name' => 'Giulia']);
        $this->travelTo(now()->setTimezone('Europe/Rome')->setDate(2026, 9, 7)->setTime(10, 0));

        Order::factory()->create(['created_by' => $user->id, 'store_name' => 'Atelier Centro']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Buongiorno, Giulia')
            ->assertSee('lunedì 7 settembre 2026')
            ->assertDontSee('Dati dimostrativi.')
            ->assertSee('Atelier Centro')
            ->assertSee('Spedizioni in corso')
            ->assertSee('Nuova richiesta');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_evening_greeting_uses_italian_time(): void
    {
        $user = User::factory()->create(['name' => 'Marco']);
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 9, 7)->setTime(17, 0));

        $this->actingAs($user)->get('/dashboard')->assertSee('Buonasera, Marco');
    }

    public function test_navigation_and_notification_panel_are_accessible_from_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')
            ->assertSee('Navigazione rapida')
            ->assertSee('Apri menu account')
            ->assertSee('Apri notifiche')
            ->assertSee('Il mio profilo');
    }
}
