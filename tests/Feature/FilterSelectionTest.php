<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_combines_account_rider_state_dates_and_preserves_pagination_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        $orders = Order::factory()->count(21)->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Cancelled]);
        $filters = ['customer_id' => $customer->slug, 'rider_id' => $rider->slug, 'status' => 'delivered', 'from' => now('Europe/Rome')->toDateString(), 'to' => now('Europe/Rome')->toDateString()];
        $response = $this->actingAs($admin)->get('/orders/history?'.http_build_query($filters))->assertOk();
        $response->assertViewHas('orders', fn ($page) => $page->total() === 21 && $page->contains('id', $orders->last()->id));
        $page = $response->viewData('orders');
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $next);
        foreach ($filters as $key => $value) {
            $this->assertSame((string) $value, $next[$key]);
        }
        $this->assertSame('2', $next['day_page']);
        $this->assertSame(now('Europe/Rome')->toDateString(), $next['day']);
    }

    public function test_search_matches_recipient_and_saved_zone_without_exposing_other_riders_orders(): void
    {
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'recipient_name' => 'Destinatario Test A Verifica', 'delivery_city' => 'Napoli', 'delivery_zone' => 'Aversa Nord']);
        Order::factory()->create(['rider_id' => $other->id, 'status' => OrderStatus::OutForDelivery, 'recipient_name' => 'Destinatario Test A Verifica', 'delivery_city' => 'Napoli', 'delivery_zone' => 'Aversa Nord']);
        $this->actingAs($rider)->get('/orders/in-progress?q=Destinatario%20Test%20A&zone=Aversa')->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $order->id);
        $this->get('/orders/in-progress?rider_id='.$other->slug)->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
    }

    public function test_statistics_retains_historical_year_when_another_filter_is_applied(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($admin)->get('/customers/'.$customer->slug.'?period=year&year=2025&status=delivered')->assertOk()
            ->assertViewHas('period', fn ($period) => $period->start->year === 2025 && $period->end->year === 2025)
            ->assertSee('name="year" value="2025"', false);
    }
}
