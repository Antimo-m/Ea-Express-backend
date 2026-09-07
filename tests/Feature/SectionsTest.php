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
            ['/tracking', 'Tracking'],
            ['/messages', 'Messaggi'],
            ['/balance', 'Bilancio'],
            ['/reports', 'Resoconti'],
            ['/settings', 'Impostazioni'],
        ];
    }

    #[DataProvider('sections')]
    public function test_future_sections_require_authentication(string $path, string $title): void
    {
        $this->get($path)->assertRedirect(route('login'));
    }

    #[DataProvider('sections')]
    public function test_authenticated_users_see_preparation_pages(string $path, string $title): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get($path)
            ->assertOk()
            ->assertSee('<title>'.$title.' · EA-Express</title>', false)

            ->assertSee('aria-current="page"', false);
    }

    public function test_orders_entry_point_redirects_authenticated_users_to_incoming_orders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/orders')->assertRedirect('/orders/incoming');
    }

    public function test_orders_entry_point_is_protected(): void
    {
        $this->get('/orders')->assertRedirect(route('login'));
    }

    public function test_no_order_creation_endpoint_is_exposed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/orders/incoming', [])->assertStatus(405);
    }
}
