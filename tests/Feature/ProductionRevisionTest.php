<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionRevisionTest extends TestCase
{
    use RefreshDatabase;

    public static function workloads(): array
    {
        return ['empty' => [0, 0, 0, 0], 'cancelled only' => [0, 0, 3, 0], 'half completed' => [1, 1, 1, 50], 'all valid completed' => [2, 0, 1, 100]];
    }

    #[DataProvider('workloads')]
    public function test_cancelled_and_rejected_orders_do_not_reduce_rider_progress(int $delivered, int $active, int $cancelled, int $progress): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        foreach ([OrderStatus::Delivered->value => $delivered, OrderStatus::Accepted->value => $active, OrderStatus::Cancelled->value => $cancelled] as $status => $count) {
            Order::factory()->count($count)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-11-10', 'status' => $status]);
        }
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-11-10', 'status' => OrderStatus::Rejected]);
        $response = $this->actingAs($admin)->get(route('rider-operations.show', $rider));
        $response->assertViewHas('counts', fn ($counts): bool => $counts['progress'] === $progress && $counts['valid'] === $delivered + $active && $counts['cancelled'] === $cancelled);
        if ($delivered + $active === 0) {
            $response->assertSee('Nessuna consegna prevista');
        }
    }

    public static function zoneSizes(): array
    {
        return ['no riders' => [0], 'one' => [1], 'three' => [3], 'four' => [4], 'six' => [6]];
    }

    #[DataProvider('zoneSizes')]
    public function test_zone_keeps_every_distinct_rider_in_selected_period(int $count): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $riders = User::factory()->count($count)->create();
        foreach ($riders as $rider) {
            Order::factory()->count(2)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-11-10', 'delivery_zone' => 'Zona condivisa']);
        }
        if ($count === 0) {
            Order::factory()->create(['rider_id' => null, 'pickup_date' => '2026-11-10', 'delivery_zone' => 'Zona condivisa']);
        }
        Order::factory()->create(['rider_id' => User::factory(), 'pickup_date' => '2026-11-09', 'delivery_zone' => 'Zona condivisa']);
        $data = $this->actingAs($admin)->getJson('/rider-operations/feed?period=custom&from=2026-11-10&to=2026-11-10')->assertOk()->json();
        $zone = collect($data['zones'])->firstWhere('name', 'Zona condivisa');
        $this->assertCount($count, $zone['riders']);
        $this->assertSame($count, count(array_unique(array_column($zone['riders'], 'slug'))));
    }

    public function test_dashboard_lists_are_limited_to_three_recent_records(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(7)->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        Order::factory()->count(6)->create();
        $this->actingAs($admin)->get('/dashboard')->assertViewHas('requests', fn ($orders): bool => $orders->count() === 3)->assertViewHas('shipments', fn ($orders): bool => $orders->count() === 3);
        $this->actingAs($rider)->get('/dashboard')->assertViewHas('shipments', fn ($orders): bool => $orders->count() === 3);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/dashboard')->assertJsonCount(3, 'recent')->assertJsonCount(3, 'next_pickups');
    }

    public function test_receipt_display_uses_ledger_instead_of_stale_paid_flag(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'price_cents' => 1000, 'paid_at' => now()]);
        $this->actingAs($admin)->get(route('orders.show', $order))->assertSee('Incasso da registrare')->assertDontSee('Incasso effettivo registrato')->assertDontSee('Tracking vettore');
        $payment = new PaymentEntry;
        $payment->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'amount_cents' => 400, 'cash_amount_cents' => 400, 'note' => 'Acconto verificato'])->save();
        $this->get(route('orders.show', $order))->assertSee('Incasso parziale')->assertSee('Residuo:')->assertDontSee('Incasso effettivo registrato');
        $payment = new PaymentEntry;
        $payment->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'amount_cents' => 600, 'cash_amount_cents' => 600, 'note' => 'Saldo verificato'])->save();
        $this->get(route('orders.show', $order))->assertSee('Incasso effettivo registrato');
    }

    public function test_refund_and_reversal_remove_the_paid_claim_in_staff_and_customer_views(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1000]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Verifica contabile'])->assertOk();
        $this->get(route('orders.show', $order))->assertSee('Incasso stornato')->assertDontSee('Incasso effettivo registrato');
        $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 3, 'note' => 'Denaro restituito al cliente'])->assertOk();
        $this->get(route('orders.show', $order))->assertSee('Incasso rimborsato')->assertDontSee('Incasso effettivo registrato');
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertJsonPath('data.receipt.state', 'refunded')->assertJsonPath('data.receipt.amount_cents', 0);
    }

    public static function prices(): array
    {
        return ['accepted' => ['agreed', 'green'], 'rejected' => ['rejected', 'danger'], 'pending customer' => ['awaiting_customer', 'blue'], 'pending rider' => ['awaiting_rider', 'blue']];
    }

    #[DataProvider('prices')]
    public function test_price_badges_share_explicit_semantic_tones(string $state, string $tone): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'pricing_version' => 1, 'price_state' => $state, 'price_cents' => 1000]);
        $this->actingAs($admin)->get(route('orders.show', $order))->assertSee('tone-'.$tone, false);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertJsonPath('data.price_tone', $tone);
    }

    public function test_customer_history_has_ten_per_page_and_keeps_period_and_status_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->count(23)->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => '2026-11-10 10:00:00']);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-11-09 10:00:00']);
        $url = route('customers.show', $customer).'?period=custom&from=2026-11-10&to=2026-11-10&status=delivered';
        $first = $this->actingAs($admin)->get($url)->assertViewHas('orders', fn ($orders): bool => $orders->total() === 23 && $orders->count() === 10)->assertSee('aria-label="Search"', false)->viewData('orders');
        $second = $this->get($url.'&page=2')->assertViewHas('orders', fn ($orders): bool => $orders->count() === 10)->viewData('orders');
        $this->assertSame([], array_intersect($first->modelKeys(), $second->modelKeys()));
        $this->assertStringContainsString('status=delivered', $second->nextPageUrl());
        $this->assertStringContainsString('from=2026-11-10', $second->nextPageUrl());
        $this->get($url.'&page=3')->assertViewHas('orders', fn ($orders): bool => $orders->count() === 3);
    }

    public function test_history_prints_entire_selected_day_beyond_page_with_filters_and_permissions(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $attributes = ['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'created_at' => '2026-11-09 12:00:00'];
        $orders = Order::factory()->count(31)->create($attributes);
        $foreign = Order::factory()->create([...$attributes, 'rider_id' => $other->id]);
        Order::factory()->create([...$attributes, 'created_at' => '2026-11-08 12:00:00']);
        $this->actingAs($admin)->get('/orders/history')->assertSee('data-history-print-start', false)->assertDontSee('Stampa etichette della pagina');
        $url = '/labels?scope=history&day=2026-11-09&rider_id='.$rider->slug.'&customer_id='.$customer->slug;
        $labels = $this->get($url)->assertOk()->assertDontSee($foreign->reference)->getContent();
        $this->assertSame(31, substr_count($labels, '<article class="label">'));
        $this->actingAs($rider)->get($url)->assertViewHas('orders', fn ($printed): bool => $printed->count() === 31);
        $this->get('/labels?ids[]='.$foreign->slug)->assertNotFound();
        $this->get('/labels?ids[]='.$orders->first()->id)->assertSessionHasErrors('ids.0');
        $this->getJson('/labels?scope=history&day=2026-11-07')->assertUnprocessable();
        $this->get('/labels?scope=history&day=2026-11-08')->assertViewHas('orders', fn ($printed): bool => $printed->count() === 1);
    }

    public function test_customer_day_selection_counts_and_prints_all_filtered_owned_orders_only(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $orders = Order::factory()->count(27)->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-10']);
        Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-09']);
        Order::factory()->create(['customer_id' => User::factory()->state(['role' => UserRole::Customer]), 'pickup_date' => '2026-11-10']);
        $url = '/api/v1/customer/orders/labels?scope=filtered&day=2026-11-10';
        $this->actingAs($customer, 'customer')->getJson($url.'&preview=1')->assertOk()->assertJsonPath('count', 27);
        $this->getJson($url)->assertOk()->assertJsonCount(27, 'data');
        $this->getJson($url.'&q=missing')->assertUnprocessable();
        $this->getJson('/api/v1/customer/orders/labels?ids[]='.$orders->first()->slug)->assertJsonCount(1, 'data');
    }

    public function test_revision_browser_fixtures(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Cliente per collaudo funzionale']);
        $riders = User::factory()->count(6)->create();
        foreach ($riders as $index => $rider) {
            Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-11-10', 'delivery_zone' => 'Zona condivisa', 'status' => $index < 2 ? OrderStatus::Cancelled : OrderStatus::Accepted]);
        }
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $riders->first()->id, 'pickup_date' => '2026-11-10', 'status' => OrderStatus::Delivered, 'price_cents' => 1000, 'pricing_version' => 1, 'price_state' => 'agreed']);
        Order::factory()->count(25)->create(['customer_id' => $customer->id, 'rider_id' => $riders->first()->id, 'created_at' => '2026-11-09 12:00:00', 'status' => OrderStatus::Delivered]);
        $this->actingAs($admin);
        $pages = ['revision-history.html' => '/orders/history', 'revision-order.html' => route('orders.show', $order), 'revision-customer.html' => route('customers.show', $customer), 'revision-rider.html' => route('rider-operations.show', $riders->first()), 'revision-zones.html' => '/rider-operations', 'revision-dashboard.html' => '/dashboard', 'revision-create.html' => '/orders/create'];
        foreach ($pages as $file => $url) {
            $response = $this->get($url)->assertOk();
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/'.$file, $response->getContent());
            }
        }
        $order->price_state = 'awaiting_customer';
        $order->save();
        $pendingPrice = $this->get(route('orders.show', $order))->assertOk()->assertSee('tone-blue', false);
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/revision-pending-price.html', $pendingPrice->getContent());
        }

    }
}
