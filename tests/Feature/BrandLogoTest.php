<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Il logo è vettoriale e in linea: nessuna immagine da scaricare, nemmeno dietro il cancello o in stampa. */
class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    private function assertInlineLogo(TestResponse $response): void
    {
        $response->assertSee('class="ea-logo', false)->assertSee('aria-label="EA-Express"', false)->assertSee('fill="#f2a560"', false)
            ->assertSee('overflow="visible"', false)->assertDontSee('brand.svg');
    }

    public function test_public_pages_show_the_inline_logo(): void
    {
        $this->assertInlineLogo($this->get('/')->assertOk());
        $this->assertInlineLogo($this->get('/login')->assertOk());
        $this->assertInlineLogo($this->get('/pagina-inesistente')->assertNotFound());
    }

    public function test_gate_page_shows_the_logo_without_loading_files(): void
    {
        $response = $this->withServerVariables(['EA_ACCESS_GATE' => 'area'])->get('/accesso-negato')->assertForbidden();

        $this->assertInlineLogo($response);
        $response->assertDontSee('<img', false)->assertDontSee('<link', false);
    }

    public function test_staff_sidebar_and_printed_label_show_the_inline_logo(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $order = Order::factory()->create();

        $this->assertInlineLogo($this->get('/dashboard')->assertOk());
        $label = $this->get(route('labels.index', ['ids' => [$order->slug]]))->assertOk();
        $this->assertInlineLogo($label);
        $label->assertSee('ea-logo--paper', false);
    }
}
