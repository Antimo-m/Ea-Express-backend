<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function backHref(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('~<nav class="(?:page-back|rider-page-back)"[^>]*>.*?href="([^"]+)"~s', $response->getContent(), $match), 'Pulsante Torna indietro non trovato');

        return html_entity_decode($match[1]);
    }

    public function test_pending_history_returns_to_pending_with_its_filters_not_to_the_balance(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create();
        $this->actingAs($admin)->get('/pending?pending_state=all')->assertOk();

        $back = $this->backHref($this->get(route('audits.index', ['type' => 'pending_accounts', 'slug' => $account->slug]))->assertOk());

        $this->assertSame('/pending?pending_state=all', $back);
    }

    public function test_history_without_navigation_context_uses_the_section_of_the_resource(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        $this->actingAs($admin);
        $this->assertSame(route('pending.index'), $this->backHref($this->get(route('audits.index', ['type' => 'pending_accounts', 'slug' => $account->slug]))));
        $this->assertSame(route('orders.history'), $this->backHref($this->get(route('audits.index', ['type' => 'orders', 'slug' => $order->slug]))));
    }

    /** @return array<string, array{OrderStatus, string}> */
    public static function orderSections(): array
    {
        return ['in entrata' => [OrderStatus::Received, 'orders.incoming'], 'in corso' => [OrderStatus::InTransit, 'orders.in-progress'], 'storico' => [OrderStatus::Delivered, 'orders.history']];
    }

    #[DataProvider('orderSections')]
    public function test_order_detail_returns_to_its_own_section_and_keeps_the_list_filters(OrderStatus $status, string $section): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => $status, 'rider_id' => $admin->id, 'delivered_at' => $status === OrderStatus::Delivered ? now() : null]);

        $this->actingAs($admin);
        $this->assertSame(route($section), $this->backHref($this->get(route('orders.show', $order))->assertOk()));
        $this->get(route($section, ['q' => 'Rossi']))->assertOk();
        $this->assertSame(parse_url(route($section), PHP_URL_PATH).'?q=Rossi', $this->backHref($this->get(route('orders.show', $order))));
    }

    public function test_customer_and_rider_details_return_to_their_lists(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);

        $this->actingAs($admin);
        $this->assertSame(route('customers.index'), $this->backHref($this->get(route('customers.show', $customer))->assertOk()));
        $this->assertSame(route('users.index'), $this->backHref($this->get(route('rider-operations.show', $rider))->assertOk()));
    }

    /** @return array<string, array{string}> */
    public static function unsafeTargets(): array
    {
        return ['esterno' => ['https://evil.example/pending'], 'senza schema' => ['//evil.example/pending'], 'javascript' => ['javascript:alert(1)'], 'dettaglio' => ['/orders/history/../create'], 'backslash' => ['/\\evil.example']];
    }

    #[DataProvider('unsafeTargets')]
    public function test_return_targets_outside_the_section_homes_are_ignored(string $target): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create();

        $back = $this->backHref($this->actingAs($admin)->get(route('audits.index', ['type' => 'pending_accounts', 'slug' => $account->slug, 'return_to' => $target]))->assertOk());

        $this->assertSame(route('pending.index'), $back);
    }

    public function test_riders_are_never_sent_back_to_admin_only_sections(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $order = Order::factory()->create(['status' => OrderStatus::InTransit, 'rider_id' => $rider->id]);

        $back = $this->backHref($this->actingAs($rider)->withSession(['section_return' => '/customers'])->get(route('orders.show', ['order' => $order, 'return_to' => '/dashboard']))->assertOk());

        $this->assertSame('/dashboard', $back);
        $this->assertSame(route('orders.in-progress'), $this->backHref($this->get(route('orders.show', ['order' => $order, 'return_to' => '/balance']))));
        $this->assertSame(route('orders.in-progress'), $this->backHref($this->get(route('orders.show', ['order' => $order, 'return_to' => '/customers']))));
    }
}
