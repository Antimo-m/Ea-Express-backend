<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accountPath(string $slug, string $kind = 'customer', string $date = '2026-11-07'): string
    {
        return '/pickups/accounts/'.$slug.'/orders?'.http_build_query(['date' => $date, 'kind' => $kind]);
    }

    public function test_summary_and_account_orders_require_staff_authentication(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $paths = ['/pickups/day?date=2026-11-07', $this->accountPath($customer->slug)];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach ($paths as $path) {
            $this->actingAs($customer)->getJson($path)->assertForbidden();
        }
    }

    public function test_unverified_and_inactive_riders_cannot_read_either_level_of_the_agenda(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $unverified = User::factory()->unverified()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        foreach (['/pickups/day?date=2026-11-07', $this->accountPath($customer->slug)] as $path) {
            $this->actingAs($unverified)->getJson($path)->assertForbidden();
            $this->actingAs($inactive)->get($path)->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_account_references_reject_numeric_ids_and_wrong_resource_kinds(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();
        Order::factory()->create(['pickup_date' => '2026-11-07', 'customer_id' => $rider->id]);
        $manual = Order::factory()->create(['pickup_date' => '2026-11-07', 'customer_id' => null]);
        $customerOrder = Order::factory()->create(['pickup_date' => '2026-11-07', 'customer_id' => $customer->id]);
        $this->actingAs($admin);

        foreach ([$this->accountPath((string) $customer->id), $this->accountPath((string) $manual->id, 'manual'), $this->accountPath($rider->slug), $this->accountPath($manual->slug), $this->accountPath($customer->slug, 'manual'), $this->accountPath($customerOrder->slug, 'manual')] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->getJson($this->accountPath($manual->slug, 'manual'))->assertOk();
        $this->getJson($this->accountPath($customer->slug))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_rider_cannot_discover_another_riders_customer_or_manual_group(): void
    {
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Account riservato altro rider']);
        $foreign = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $other->id, 'pickup_date' => '2026-11-07', 'status' => OrderStatus::Accepted, 'store_name' => 'Ordine riservato altro rider']);
        $manual = Order::factory()->create(['customer_id' => null, 'rider_id' => $other->id, 'pickup_date' => '2026-11-07', 'status' => OrderStatus::Accepted]);

        $this->actingAs($rider)->getJson('/pickups/day?date=2026-11-07')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('total_orders', 0)->assertDontSee($customer->slug)->assertDontSee($foreign->slug)->assertDontSee($manual->slug)->assertDontSee('Account riservato altro rider');
        $this->getJson($this->accountPath($customer->slug))->assertNotFound();
        $this->getJson($this->accountPath($manual->slug, 'manual'))->assertNotFound();
    }

    public function test_mixed_customer_assignment_never_returns_hidden_orders_on_later_pages(): void
    {
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $visible = Order::factory()->count(21)->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-11-07', 'status' => OrderStatus::Accepted, 'store_name' => 'Ritiro assegnato visibile']);
        $foreign = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $other->id, 'pickup_date' => '2026-11-07', 'status' => OrderStatus::Accepted, 'store_name' => 'Ritiro segreto altro rider']);
        $received = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-11-07', 'status' => OrderStatus::Received, 'store_name' => 'Richiesta ancora riservata']);
        $pickedUp = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-11-07', 'status' => OrderStatus::Rescheduled, 'store_name' => 'Gia ritirato escluso']);
        $pickedUp->events()->create(['user_id' => $rider->id, 'status' => OrderStatus::PickedUp]);
        $this->actingAs($rider);

        $summary = $this->getJson('/pickups/day?date=2026-11-07')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('total_orders', 21)->assertJsonPath('data.0.orders_count', 21)->assertJsonPath('meta.total', 1);
        $this->assertSame([], array_intersect(['id', 'customer_id', 'rider_id', 'created_by', 'email', 'phone'], array_keys($summary->json('data.0'))));
        $slugs = [];
        foreach ([1, 2] as $page) {
            $response = $this->getJson($this->accountPath($customer->slug).'&page='.$page)->assertOk()->assertSee('Ritiro assegnato visibile')
                ->assertJsonPath('account.orders_count', 21)->assertJsonPath('meta.total', 21)->assertJsonCount($page === 1 ? 20 : 1, 'data');
            $slugs = [...$slugs, ...array_column($response->json('data'), 'slug')];
            foreach ($response->json('data') as $order) {
                $this->assertSame([], array_intersect(['id', 'customer_id', 'rider_id', 'created_by', 'tracking_token', 'conversation_token'], array_keys($order)));
                $this->assertSame($rider->slug, $order['rider']['slug']);
                $this->assertSame(['slug', 'name'], array_keys($order['rider']));
            }
            foreach ([$foreign, $received, $pickedUp] as $hidden) {
                $response->assertDontSee($hidden->slug)->assertDontSee($hidden->store_name);
            }
        }
        $this->assertSame($visible->pluck('slug')->all(), $slugs);
    }

    public function test_manual_order_reference_is_a_singleton_even_when_sender_details_match(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $first = Order::factory()->create(['customer_id' => null, 'pickup_date' => '2026-11-07', 'store_name' => 'Mittente manuale uguale']);
        $second = Order::factory()->create(['customer_id' => null, 'pickup_date' => '2026-11-07', 'store_name' => 'Mittente manuale uguale']);

        $this->actingAs($admin)->getJson($this->accountPath($first->slug, 'manual'))->assertOk()->assertJsonPath('account.kind', 'manual')->assertJsonPath('account.slug', $first->slug)->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertSee($first->slug)->assertDontSee($second->slug);
        $this->getJson($this->accountPath($first->slug, 'manual', '2026-11-08'))->assertNotFound();
    }

    public function test_account_orders_reject_invalid_dates_pages_and_kinds(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-07']);
        $this->actingAs($admin);

        foreach (['2026-02-30', '0000-01-01', '2026-11-07 OR 1=1'] as $date) {
            $this->getJson($this->accountPath($customer->slug, date: $date))->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        foreach (['0', '-1', '1.5', '10001', '1 OR 1=1'] as $page) {
            $this->getJson($this->accountPath($customer->slug).'&page='.urlencode($page))->assertUnprocessable()->assertJsonPath('message', 'Pagina non valida: usa un valore tra 1 e 10000.');
            $this->getJson('/pickups/day?date=2026-11-07&page='.urlencode($page))->assertUnprocessable()->assertJsonPath('message', 'Pagina non valida: usa un valore tra 1 e 10000.');
        }
        $this->getJson($this->accountPath($customer->slug).'&per_page=21')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/pickups/day?date=2026-11-07&per_page=11')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($this->accountPath($customer->slug, 'admin'))->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_the_last_supported_calendar_day_remains_readable_without_year_overflow(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '9999-12-31']);

        $this->actingAs($admin)->getJson('/pickups/day?date=9999-12-31')->assertOk()->assertJsonPath('total_orders', 1)->assertJsonPath('data.0.orders_count', 1);
        $this->getJson($this->accountPath($customer->slug, date: '9999-12-31'))->assertOk()->assertJsonPath('data.0.slug', $order->slug);
    }

    public function test_account_and_order_names_are_escaped_in_both_html_fragments(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $name = '<script>alert(1)</script>';
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => $name]);
        Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-07', 'store_name' => $name]);

        $this->actingAs($admin)->get('/pickups/day?date=2026-11-07')->assertOk()->assertDontSee($name, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->get($this->accountPath($customer->slug))->assertOk()->assertDontSee($name, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
