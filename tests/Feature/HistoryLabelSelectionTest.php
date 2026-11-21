<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HistoryLabelSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function closed(string $createdAt, array $attributes = []): Order
    {
        return Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'created_at' => Carbon::parse($createdAt, 'Europe/Rome'), ...$attributes]);
    }

    public function test_history_prints_whole_days_minus_exclusions_plus_single_orders_without_duplicates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $first = collect(range(1, 4))->map(fn () => $this->closed('2026-11-18 10:00'));
        $second = collect(range(1, 3))->map(fn () => $this->closed('2026-11-19 10:00'));
        $this->closed('2026-11-18 11:00', ['parcel_count' => 0]);
        $url = fn (array $query) => '/labels?'.http_build_query(['scope' => 'history-selection', ...$query]);
        $printed = fn ($response) => $response->assertOk()->viewData('orders')->pluck('id')->sort()->values()->all();

        $this->actingAs($admin);
        $this->assertSame($first->pluck('id')->sort()->values()->all(), $printed($this->get($url(['days' => ['2026-11-18']]))));
        $expected = $first->slice(1)->pluck('id')->merge([$second[0]->id, $second[2]->id])->sort()->values()->all();
        $this->assertSame($expected, $printed($this->get($url(['days' => ['2026-11-18'], 'exclude' => [$first[0]->slug], 'ids' => [$second[0]->slug, $second[2]->slug, $first[1]->slug]]))), 'Esclusioni rispettate e nessun ordine duplicato.');
        $this->assertSame([$second[1]->id], $printed($this->get($url(['ids' => [$second[1]->slug]]))));
        $this->get($url([]))->assertStatus(422);
        $this->get($url(['days' => ['2026-11-18'], 'exclude' => $first->pluck('slug')->all()]))->assertRedirect(route('orders.in-progress'));
        $this->getJson($url(['days' => ['18/11/2026']]))->assertUnprocessable();
    }

    public function test_rider_selection_includes_only_visible_orders(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 12:00', 'Europe/Rome'));
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $mine = $this->closed('2026-11-18 10:00', ['rider_id' => $rider->id]);
        $other = $this->closed('2026-11-18 10:00', ['rider_id' => User::factory()->create(['role' => UserRole::Rider])->id]);

        $orders = $this->actingAs($rider)->get('/labels?'.http_build_query(['scope' => 'history-selection', 'days' => ['2026-11-18'], 'ids' => [$other->slug]]))->assertOk()->viewData('orders');
        $this->assertSame([$mine->id], $orders->pluck('id')->all());
    }

    public function test_history_page_offers_icon_print_and_toggle_checkboxes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 12:00', 'Europe/Rome'));
        $this->closed('2026-11-18 10:00');
        $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/orders/history?day=2026-11-18')->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<a[^>]*aria-label="Stampa etichette"[^>]*data-history-print-start|data-history-print-start[^>]*aria-label="Stampa etichette"/s', $html);
        $response->assertDontSee('type="radio"', false)->assertSee('data-history-label-day="2026-11-18"', false)->assertSee('data-history-label-single=', false)
            ->assertSee('Deseleziona tutte')->assertSee('data-history-print-toolbar', false);
        $this->assertStringContainsString('aria-label="Selezione etichette" hidden', $html);
    }
}
