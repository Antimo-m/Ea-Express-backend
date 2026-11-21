<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupDayLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_day_print_includes_only_the_orders_each_staff_role_can_see(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $otherRider = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $mine = Order::factory()->count(3)->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-11-21', 'status' => OrderStatus::Accepted]);
        $others = Order::factory()->count(2)->create(['customer_id' => $customer->id, 'rider_id' => $otherRider->id, 'pickup_date' => '2026-11-21', 'status' => OrderStatus::Accepted]);
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-11-22', 'status' => OrderStatus::Accepted]);
        $url = route('labels.index', ['scope' => 'pickup', 'date' => '2026-11-21', 'account' => $customer->slug, 'kind' => 'customer']);

        $this->actingAs($admin)->get($url)->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->sort()->values()->all() === $mine->concat($others)->pluck('id')->sort()->values()->all());
        $this->actingAs($rider)->get($url)->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->sort()->values()->all() === $mine->pluck('id')->sort()->values()->all());
        $this->actingAs($rider)->get(route('labels.index', ['ids' => [$others->first()->slug]]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Rider]))->get($url)->assertNotFound();
        $this->actingAs($admin)->get(route('labels.index', ['scope' => 'pickup', 'date' => '2026-11-21', 'account' => $customer->slug, 'kind' => 'other']))->assertSessionHasErrors('kind');
    }

    public function test_agenda_account_offers_selection_counter_and_full_day_print(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(13)->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-21', 'status' => OrderStatus::Accepted]);

        $this->actingAs($admin)->get(route('pickups.account-orders', ['account' => $customer->slug, 'date' => '2026-11-21', 'per_page' => 10]))->assertOk()
            ->assertSee('10 etichette selezionate su 13 ordini')
            ->assertSee('data-label-carry', false)
            ->assertSee('scope=pickup', false)->assertSee('Stampa tutta la giornata');
    }
}
