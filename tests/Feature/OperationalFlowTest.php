<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalFlowTest extends TestCase
{
    use RefreshDatabase;

    public static function paginationCases(): array
    {
        $cases = [];
        foreach (['incoming' => OrderStatus::Received, 'in-progress' => OrderStatus::Accepted, 'history' => OrderStatus::Delivered] as $section => $status) {
            foreach ([0 => 1, 1 => 1, 20 => 1, 21 => 2, 40 => 2, 41 => 3] as $count => $pages) {
                $cases[$section.' '.$count] = [$section, $status, $count, $pages];
            }
        }

        return $cases;
    }

    #[DataProvider('paginationCases')]
    public function test_operational_lists_limit_results_and_keep_filters(string $section, OrderStatus $status, int $count, int $pages): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->count($count)->create(['created_by' => $admin->id, 'status' => $status, 'recipient_name' => 'Verifica paginazione']);
        $response = $this->actingAs($admin)->get('/orders/'.$section.'?q=Verifica');
        $response->assertOk();
        $page = $response->viewData('orders');
        $this->assertSame($count, $page->total());
        $this->assertSame(20, $page->perPage());
        $this->assertSame($pages, $page->lastPage());
        $this->assertCount(min(20, $count), $page->items());
        if ($pages > 1) {
            $this->assertStringContainsString('q=Verifica', $page->nextPageUrl());
            $pageName = $section === 'history' ? 'day_page' : 'page';
            $last = $this->get('/orders/'.$section.'?q=Verifica&'.$pageName.'='.$pages)->assertOk()->viewData('orders');
            $this->assertFalse($last->hasMorePages());
            $this->assertSame($pages, $last->currentPage());
            $this->assertCount($count - 20 * ($pages - 1), $last->items());
        }
    }

    public function test_out_of_range_pages_redirect_to_the_last_valid_page_and_keep_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->count(21)->create(['created_by' => $admin->id, 'status' => OrderStatus::Accepted, 'recipient_name' => 'Verifica paginazione']);
        $this->actingAs($admin)->get('/orders/in-progress?q=Verifica&page=6')->assertRedirect(route('orders.in-progress', ['q' => 'Verifica', 'page' => 2]));
    }

    public static function senderTypes(): array
    {
        return [['private'], ['business'], ['online_shop']];
    }

    private function bookingData(): array
    {
        return ['store_name' => 'Mittente prova', 'recipient_name' => 'Destinatario prova', 'recipient_phone' => '+390000000000', 'pickup_address' => 'Via Roma', 'pickup_street_number' => '10', 'pickup_postal_code' => '80121', 'pickup_city' => 'Napoli', 'delivery_address' => 'Via Roma', 'delivery_street_number' => '20', 'delivery_postal_code' => '81100', 'delivery_province' => 'Caserta', 'delivery_region' => 'Campania', 'delivery_city' => 'Caserta', 'pickup_date' => now('Europe/Rome')->addDays(2)->toDateString(), 'pickup_from' => '09:00', 'pickup_to' => '12:00', 'parcel_count' => 1, 'parcel_value' => '25.00', 'package_type' => 'standard', 'category' => 'clothing', 'urgency' => 'standard'];
    }

    #[DataProvider('senderTypes')]
    public function test_registered_account_type_controls_customer_booking_and_prefills_staff_booking(string $type): void
    {
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'postal_codes' => ['81100'], 'price_cents' => 850]);
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Cliente prova', 'email' => 'cliente@example.test', 'password' => 'PasswordSicura123', 'password_confirmation' => 'PasswordSicura123', 'sender_type' => $type])->assertAccepted();
        $customer = User::where('email', 'cliente@example.test')->sole();
        $this->assertSame($type, $customer->sender_type);
        $this->actingAs($customer, 'customer');
        $review = $this->postJson('/api/v1/customer/orders/checkout', $this->bookingData())->assertOk()->json();
        $this->assertSame($type, $review['data']['sender_type']);
        $this->postJson('/api/v1/customer/orders', [...$review['data'], 'checkout_token' => $review['checkout_token']])->assertCreated();
        $this->assertSame($type, Order::sole()->sender_type);
        $override = $type === 'private' ? 'business' : 'private';
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->bookingData(), 'sender_type' => $override])->assertOk()->assertJsonPath('data.sender_type', $type);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'web');
        $staffReview = $this->post('/orders', [...$this->bookingData(), 'customer_id' => $customer->slug])->assertOk()->viewData('review');
        $this->assertSame($type, $staffReview['data']['sender_type']);
        $this->get('/orders/create')->assertOk()->assertSee('data-identity=', false);
    }

    public function test_registration_requires_an_allowed_type_and_legacy_business_accounts_keep_their_identity(): void
    {
        $data = ['name' => 'Cliente', 'email' => 'cliente@example.test', 'password' => 'PasswordSicura123', 'password_confirmation' => 'PasswordSicura123'];
        $this->postJson('/api/v1/customer/auth/register', $data)->assertUnprocessable()->assertJsonValidationErrors('sender_type');
        $this->postJson('/api/v1/customer/auth/register', [...$data, 'sender_type' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('sender_type');
        $this->assertDatabaseCount('users', 0);
        $legacy = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($legacy, 'customer')->getJson('/api/v1/customer/auth/me')->assertOk()->assertJsonPath('user.sender_type', 'business');
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->bookingData(), 'sender_type' => 'admin'])->assertOk()->assertJsonPath('data.sender_type', 'business');
    }

    public function test_profile_validates_account_type_and_changes_the_default_for_later_booking(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'sender_type' => 'business']);
        $this->actingAs($customer, 'customer');
        $profile = ['name' => $customer->name, 'email' => $customer->email];
        $this->patchJson('/api/v1/customer/profile', [...$profile, 'sender_type' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('sender_type');
        $this->assertSame('business', $customer->fresh()->sender_type);
        $this->patchJson('/api/v1/customer/profile', [...$profile, 'sender_type' => 'private', 'business_type' => 'Da rimuovere'])->assertOk();
        $this->assertSame('private', $customer->fresh()->sender_type);
        $this->assertNull($customer->fresh()->business_type);
        $this->postJson('/api/v1/customer/orders/checkout', $this->bookingData())->assertOk()->assertJsonPath('data.sender_type', 'private');
    }

    public function test_ajax_workflow_returns_authoritative_state_and_rejects_replays(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['created_by' => $admin->id, 'status' => OrderStatus::Received, 'pricing_version' => 1, 'price_state' => 'agreed', 'price_cents' => 850, 'quoted_price_cents' => 850]);
        $this->actingAs($admin)->patchJson('/orders/'.$order->slug, ['status' => 'accepted', 'version' => 1, 'rider_id' => $rider->slug])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('status', 'accepted');
        $directory = getenv('EA_UI_FIXTURE_DIR');
        $this->actingAs($rider);
        if ($directory) {
            file_put_contents($directory.'/ajax-order.html', $this->get('/orders/'.$order->slug)->assertOk()->getContent());
        }
        foreach (['rider_arriving', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'] as $index => $status) {
            $data = ['status' => $status, 'version' => $index + 2];
            $response = $this->patchJson('/orders/'.$order->slug, $data)->assertOk()->assertJsonPath('status', $status)->assertJsonPath('version', $index + 3);
            $this->assertStringContainsString('Il percorso dell’ordine', $response->json('html'));
            $this->assertSame($status, $order->fresh()->status->value);
            $this->patchJson('/orders/'.$order->slug, $data)->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->assertSame($index + 2, $order->events()->count());
            if ($directory) {
                file_put_contents($directory.'/ajax-'.$status.'.json', json_encode($response->json()));
            }
        }
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->get('/orders/history')->assertOk()->assertSee(route('orders.show', $order), false);
        $this->actingAs(User::factory()->create())->patchJson('/orders/'.$order->slug, ['status' => 'delivered', 'version' => 7])->assertForbidden();
    }

    public function test_checkout_back_preserves_all_booking_fields_and_confirm_creates_only_once(): void
    {
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'postal_codes' => ['81100'], 'price_cents' => 850]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $data = [...$this->bookingData(), 'sender_type' => 'online_shop', 'urgency' => 'urgent', 'notes' => 'Dati da conservare'];
        $review = $this->post('/orders', $data)->assertOk()->assertSee('checkout-actions')->viewData('review');
        $this->post('/orders/checkout/edit', $review['data'])->assertRedirect(route('orders.create'))->assertSessionHasInput('sender_type', 'online_shop')->assertSessionHasInput('notes', 'Dati da conservare')->assertSessionHasInput('pickup_from', '09:00');
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if ($directory) {
            file_put_contents($directory.'/checkout.html', $this->post('/orders', $data)->assertOk()->getContent());
        }
        $submission = [...$review['data'], 'checkout_token' => $review['checkout_token']];
        $this->post('/orders', $submission)->assertRedirect();
        $this->post('/orders', $submission)->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(850, Order::sole()->price_cents);
    }

    public function test_browser_fixture_uses_an_isolated_database_with_staff_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@example.test', 'name' => 'Admin verifica']);
        $rider = User::factory()->create(['email' => 'rider@example.test', 'name' => 'Rider verifica']);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'postal_codes' => ['81100'], 'price_cents' => 850]);
        $directory = getenv('EA_UI_FIXTURE_DIR');
        $this->assertDatabaseCount('users', 2);
        if (! $directory) {
            return;
        }
        $target = new \PDO('sqlite:'.$directory.'/browser.sqlite');
        $tables = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables as $table) {
            $target->exec($table->sql);
            $writableColumns = array_column(DB::select('PRAGMA table_info("'.$table->name.'")'), 'name');
            foreach (DB::table($table->name)->get() as $row) {
                $values = array_intersect_key((array) $row, array_flip($writableColumns));
                $columns = implode(',', array_map(fn ($name) => '"'.$name.'"', array_keys($values)));
                $statement = $target->prepare('INSERT INTO "'.$table->name.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $statement->execute(array_values($values));
            }
        }
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL") as $index) {
            $target->exec($index->sql);
        }
        mkdir($directory.'/sessions');

    }
}
