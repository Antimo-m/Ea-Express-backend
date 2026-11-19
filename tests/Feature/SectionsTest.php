<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectionsTest extends TestCase
{
    use RefreshDatabase;

    public static function sections(): array
    {
        return [
            ['/orders/incoming', 'Ordini in entrata'],
            ['/orders/in-progress', 'Spedizioni in corso'],
            ['/orders/history', 'Storico ordini'],
            ['/messages', 'Messaggi'],
            ['/balance', 'Bilancio'],
            ['/reports', 'Resoconti'],
            ['/settings', 'Impostazioni'],
        ];
    }

    #[DataProvider('sections')]
    public function test_operational_sections_require_authentication(string $path, string $title): void
    {
        $this->get($path)->assertRedirect(route('login'));
    }

    #[DataProvider('sections')]
    public function test_admins_see_all_management_pages(string $path, string $title): void
    {
        $user = User::factory()->create(['role' => \App\UserRole::Admin]);

        $this->actingAs($user)->get($path)
            ->assertOk()
            ->assertSee('<title>'.$title.' · EA-Express</title>', false)

            ->assertSee('aria-current="page"', false)
            ->assertDontSee('Disponibile prossimamente');
    }

    public function test_orders_entry_point_redirects_riders_to_assigned_shipments(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/orders')->assertRedirect('/orders/in-progress');
    }

    public function test_orders_entry_point_is_protected(): void
    {
        $this->get('/orders')->assertRedirect(route('login'));
    }

    public function test_incoming_listing_does_not_accept_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/orders/incoming', [])->assertStatus(405);
    }
}
