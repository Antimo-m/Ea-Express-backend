<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EmptyLabelsTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin'])]
    #[TestWith(['rider'])]
    public function test_empty_direct_and_filtered_staff_print_requests_return_a_managed_response(string $role): void
    {
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $this->actingAs($user)->get('/labels?scope=filtered')->assertRedirect(route('orders.in-progress'))->assertSessionHas('status', 'Al momento non ci sono ordini da stampare');
        $this->getJson('/labels?scope=filtered')->assertUnprocessable()->assertJsonPath('message', 'Al momento non ci sono ordini da stampare');
        $this->get('/labels')->assertRedirect(route('orders.in-progress'));
        $this->get('/orders/in-progress')->assertSee('aria-disabled="true"', false)->assertSee('data-label-availability', false);
    }

    public function test_labels_are_scoped_to_assigned_rider_and_selected_filters_without_empty_documents(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $other = Order::factory()->create(['status' => OrderStatus::Accepted]);
        $this->actingAs($rider)->get('/labels?scope=all')->assertRedirect();
        $this->get('/labels?'.http_build_query(['ids' => [$other->slug]]))->assertNotFound();
        $order = Order::factory()->create(['status' => OrderStatus::Accepted, 'rider_id' => $rider->id, 'parcel_count' => 1]);
        $this->get('/labels?scope=filtered&q=does-not-exist')->assertRedirect();
        $this->get('/labels?scope=filtered')->assertViewHas('orders', fn ($orders): bool => $orders->sole()->slug === $order->slug);
        $this->get('/labels?'.http_build_query(['ids' => [$order->slug]]))->assertSee($order->reference);
        $this->getJson('/labels?'.http_build_query(['ids' => [$order->id]]))->assertUnprocessable();
    }

    public function test_staff_can_print_one_or_multiple_authorized_orders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $orders = Order::factory()->count(2)->create(['status' => OrderStatus::Accepted, 'parcel_count' => 1]);
        $this->actingAs($admin)->get('/labels?'.http_build_query(['ids' => $orders->pluck('slug')->all()]))->assertViewHas('orders', fn ($rows): bool => $rows->count() === 2);
        $this->get('/labels?scope=filtered&q='.$orders->first()->reference)->assertViewHas('orders', fn ($rows): bool => $rows->count() === 1);
        $this->get('/orders/in-progress?q=does-not-exist')->assertSee('aria-disabled="true"', false);
    }

    public function test_customer_labels_require_at_least_one_owned_public_slug_and_never_expose_another_customer_order(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = Order::factory()->create();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/labels')->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->getJson('/api/v1/customer/orders/labels?'.http_build_query(['ids' => [$other->slug]]))->assertNotFound();
        $this->getJson('/api/v1/customer/orders/labels?'.http_build_query(['ids' => [$other->id]]))->assertUnprocessable();
        $orders = Order::factory()->count(2)->create(['customer_id' => $customer->id, 'parcel_count' => 1]);
        $this->getJson('/api/v1/customer/orders/labels?'.http_build_query(['ids' => [$orders->first()->slug]]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $orders->first()->slug)->assertJsonMissingPath('data.0.id');
        $this->getJson('/api/v1/customer/orders/labels?'.http_build_query(['ids' => $orders->pluck('slug')->all()]))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_unauthenticated_label_requests_are_refused(): void
    {
        $this->get('/labels?scope=all')->assertRedirect(route('login'));
        $this->getJson('/api/v1/customer/orders/labels')->assertUnauthorized();
    }
}
