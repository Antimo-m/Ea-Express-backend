<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_directory_paginates_twenty_five_accounts_on_the_server(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(51)->sequence(fn ($sequence): array => [
            'role' => UserRole::Customer,
            'name' => 'Cliente '.str_pad((string) ($sequence->index + 1), 3, '0', STR_PAD_LEFT),
        ])->create();

        $page = $this->actingAs($admin)->get(route('customers.index'));

        $page->assertOk()->assertViewHas('customers', fn ($items): bool => $items->perPage() === 25 && $items->total() === 51 && $items->count() === 25 && $items->lastPage() === 3)
            ->assertSee('Cliente 025')->assertDontSee('Cliente 026')->assertSee('Pagina 1 di 3')
            ->assertSee(route('customers.show', $customers[0]), false);
        $secondPage = $this->get(route('customers.index', ['page' => 2]))
            ->assertViewHas('customers', fn ($items): bool => $items->currentPage() === 2 && $items->count() === 25)
            ->assertSee('Cliente 026')->assertSee('Cliente 050')->assertDontSee('Cliente 025')->assertDontSee('Cliente 051');
        $thirdPage = $this->get(route('customers.index', ['page' => 3]))
            ->assertViewHas('customers', fn ($items): bool => $items->count() === 1)
            ->assertSee('Cliente 051')->assertDontSee('Cliente 050');

        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            foreach (['customers-page-1' => $page, 'customers-page-2' => $secondPage, 'customers-page-3' => $thirdPage] as $name => $response) {
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
    }

    public function test_customer_search_type_and_sort_preserve_the_pagination_context(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(28)->sequence(fn ($sequence): array => [
            'role' => UserRole::Customer, 'sender_type' => 'business',
            'name' => 'Filtro '.str_pad((string) ($sequence->index + 1), 3, '0', STR_PAD_LEFT),
        ])->create();
        User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'private', 'name' => 'Filtro privato']);
        User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'business', 'name' => 'Altro account']);
        Order::factory()->count(2)->create(['customer_id' => $customers[27]->id, 'price_cents' => 2000]);
        $filters = ['q' => 'Filtro', 'sender_type' => 'business', 'sort' => 'volume'];
        $this->actingAs($admin);

        $this->get(route('customers.index', $filters))
            ->assertViewHas('customers', fn ($items): bool => $items->total() === 28 && $items->lastPage() === 2 && $items->first()->slug === $customers[27]->slug);
        $page = $this->get(route('customers.index', [...$filters, 'page' => 2]));

        $page->assertOk()->assertViewHas('customers', fn ($items): bool => $items->currentPage() === 2 && $items->count() === 3)
            ->assertDontSee('Filtro privato')->assertDontSee('Altro account');
        $previous = $page->viewData('customers')->previousPageUrl();
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        $this->assertSame('Filtro', $query['q']);
        $this->assertSame('business', $query['sender_type']);
        $this->assertSame('volume', $query['sort']);
        $this->assertSame('1', $query['page']);
        $this->get(route('customers.index', [...$filters, 'q' => 'Filtro 028']))
            ->assertViewHas('customers', fn ($items): bool => $items->total() === 1 && $items->lastPage() === 1 && $items->currentPage() === 1);
        $this->get(route('customers.index', [...$filters, 'sort' => 'value']))
            ->assertViewHas('customers', fn ($items): bool => $items->first()->slug === $customers[27]->slug);
    }
}
