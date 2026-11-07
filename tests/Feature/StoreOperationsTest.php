<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_statistics_are_private_and_use_saved_decimal_prices(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(2)->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'price_cents' => 550]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Cancelled, 'price_cents' => 9999]);
        Order::factory()->create(['customer_id' => $other->id, 'price_cents' => 9000]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?customer_id='.$other->slug)->assertOk()->assertJsonPath('total', 3)->assertJsonPath('delivered', 2)->assertJsonPath('cancelled', 1)->assertJsonMissingPath('shipping_spend_cents')->assertJsonMissingPath('prices')->assertJsonPath('change_percent', null);
        $this->getJson('/api/v1/customer/statistics?period=custom&from=2026-09-18&to=2026-09-17')->assertUnprocessable();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'web')->get('/customers/'.$customer->slug)->assertOk()->assertViewHas('summary', fn ($detail) => $detail['shipping_spend_cents'] === 1100)->assertSee('5,50');
    }

    public function test_pickups_group_by_account_and_labels_remain_distinct_and_scoped(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
        $rider = User::factory()->create();
        $a = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Fashion Test']);
        $b = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Fashion Test']);
        $orders = Order::factory()->count(2)->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted, 'customer_id' => $a->id, 'package_type' => 'fragile', 'delivery_street_number' => '22', 'delivery_postal_code' => '81100']);
        $third = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted, 'customer_id' => $b->id]);
        $hidden = Order::factory()->create(['status' => OrderStatus::Accepted, 'rider_id' => User::factory()->create()->id]);
        $dayUrl = route('pickups.day', ['date' => now('Europe/Rome')->addDay()->toDateString()]);
        $day = $this->actingAs($rider)->getJson($dayUrl)->assertOk()
            ->assertJsonPath('total_orders', 3)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $a->slug)->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.1.slug', $b->slug)->assertJsonPath('data.1.orders_count', 1);
        $this->get($dayUrl)->assertSee('data-pickup-account="'.$a->slug.'"', false)
            ->assertSee('data-pickup-account="'.$b->slug.'"', false)->assertDontSee($orders[0]->reference);
        $details = $this->getJson($day->json('data.0.orders_url'))->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($orders->pluck('slug')->all(), array_column($details->json('data'), 'slug'));
        $this->getJson($day->json('data.1.orders_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $third->slug);
        $this->get('/labels?'.http_build_query(['ids' => $orders->pluck('slug')->all()]))->assertOk()->assertSee($orders[0]->reference)->assertSee($orders[1]->reference)->assertSee('FRAGILE')->assertSee('81100')->assertDontSee('nav-item-link');
        $this->get('/labels?ids[]='.$third->slug)->assertOk()->assertSee($third->reference)->assertDontSee($orders[0]->reference);
        $this->get('/labels?'.http_build_query(['ids' => [$third->slug, $hidden->slug]]))->assertNotFound();
        $this->get('/labels')->assertSessionHasErrors('ids');
        $this->actingAs($a, 'customer')->get('/labels?ids[]='.$third->slug)->assertForbidden();
    }
}
