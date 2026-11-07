<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PickupCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_opens_in_the_rome_month_and_supports_leap_years_and_month_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 22:30:00', 'UTC'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get(route('pickups.index'))->assertOk()
            ->assertSee('Ottobre 2026')->assertSee('aria-current="date"', false)
            ->assertSee('id="pickup-day-2026-10-01"', false)
            ->assertViewHas('previousMonth', '2026-09')->assertViewHas('nextMonth', '2026-11');
        $leap = $this->get(route('pickups.index', ['month' => '2028-02']))->assertOk();
        $this->assertSame(29, substr_count($leap->getContent(), 'data-pickup-date='));
        $leap->assertSee('data-pickup-date="2028-02-29"', false)->assertDontSee('2028-02-30');
        $this->assertSame(7, substr_count($leap->getContent(), 'scope="col"'));
        $this->get(route('pickups.index', ['month' => '2026-12']))->assertOk()->assertViewHas('nextMonth', '2027-01');
        $this->get(route('pickups.index', ['month' => '0001-01']))->assertOk()->assertViewHas('previousMonth', null);
        $this->get(route('pickups.index', ['month' => '9999-12']))->assertOk()->assertViewHas('nextMonth', null);
    }

    public function test_counts_use_the_pickup_date_and_existing_awaiting_pickup_rules_for_individual_orders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        foreach (OrderStatus::cases() as $status) {
            Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'status' => $status, 'pickup_date' => '2026-10-14', 'parcel_count' => 7, 'created_at' => '2026-08-01 08:00:00']);
        }
        foreach ([OrderStatus::DeliveryIssue, OrderStatus::Rescheduled] as $status) {
            $pickedUp = Order::factory()->create(['created_by' => $admin->id, 'status' => $status, 'pickup_date' => '2026-10-14']);
            $pickedUp->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::PickedUp]);
        }
        Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-11-14']);
        $this->actingAs($admin)->get(route('pickups.index', ['month' => '2026-10']))->assertOk()
            ->assertSee('data-pickup-date="2026-10-14" data-pickup-count="6"', false)->assertSee('6 ritiri da effettuare nel mese');

        $summary = $this->getJson(route('pickups.day', ['date' => '2026-10-14']))->assertOk()
            ->assertJsonPath('total_orders', 6)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.orders_count', 6)->assertJsonPath('data.0.parcel_count', 42)
            ->assertJsonMissingPath('data.0.orders');
        $this->getJson($summary->json('data.0.orders_url'))->assertOk()->assertJsonCount(6, 'data');
        $this->getJson(route('pickups.day', ['date' => '2026-10-15']))->assertOk()
            ->assertJsonPath('total_orders', 0)->assertJsonCount(0, 'data');
        $this->get(route('pickups.day', ['date' => '2026-10-15']))->assertSee('Nessun ritiro da effettuare');
    }

    public function test_last_day_is_counted_without_spilling_into_the_next_month(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-10-31']);
        Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-11-01']);
        $this->actingAs($admin)->get(route('pickups.index', ['month' => '2026-10']))->assertOk()
            ->assertSee('data-pickup-date="2026-10-31" data-pickup-count="1"', false)->assertSee('1 ritiro da effettuare nel mese');
        $this->get(route('pickups.day', ['date' => '2026-10-31']))->assertOk()->assertViewHas('totalOrders', 1);
    }

    public function test_daily_queries_and_order_hydration_stay_bounded_when_accounts_grow(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $admin->id, 'pickup_date' => '2026-10-14']);
        $this->actingAs($admin);
        $hydratedOrders = 0;
        Order::retrieved(function () use (&$hydratedOrders): void {
            $hydratedOrders++;
        });
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('pickups.day', ['date' => '2026-10-14']))->assertOk()->assertJsonCount(1, 'data');
        $singleAccountQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(0, $hydratedOrders, 'The summary must use SQL aggregates rather than hydrated orders.');
        for ($index = 0; $index < 19; $index++) {
            Order::factory()->create(['created_by' => $admin->id, 'customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'rider_id' => User::factory()->create(['role' => UserRole::Rider])->id, 'pickup_date' => '2026-10-14']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('pickups.day', ['date' => '2026-10-14']))->assertOk()->assertJsonCount(10, 'data');
        $this->assertLessThanOrEqual($singleAccountQueries + 2, count(DB::getQueryLog()), 'Account summaries must batch identity queries.');
        DB::disableQueryLog();
        $this->assertSame(0, $hydratedOrders);
    }

    public function test_official_rescheduling_and_cancellation_update_calendar_summary_and_account_orders(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 08:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Accepted, 'pickup_date' => '2026-10-08']);
        $this->actingAs($admin)->patchJson(route('orders.pickup-schedule', $order), [
            'pickup_date' => '2026-11-02', 'pickup_from' => '10:00', 'pickup_to' => '12:00', 'reason' => 'Cambio concordato', 'version' => 1,
        ])->assertOk();
        $this->get(route('pickups.index', ['month' => '2026-10']))->assertSee('data-pickup-date="2026-10-08" data-pickup-count="0"', false);
        $this->get(route('pickups.index', ['month' => '2026-11']))->assertSee('data-pickup-date="2026-11-02" data-pickup-count="1"', false);
        $this->getJson(route('pickups.day', ['date' => '2026-10-08']))->assertOk()->assertJsonPath('total_orders', 0)->assertJsonCount(0, 'data');
        $summary = $this->getJson(route('pickups.day', ['date' => '2026-11-02']))->assertOk()->assertJsonPath('total_orders', 1)->assertJsonCount(1, 'data');
        $this->getJson($summary->json('data.0.orders_url'))->assertOk()->assertJsonPath('data.0.slug', $order->slug);
        $this->patchJson('/orders/'.$order->slug, ['status' => 'cancelled', 'version' => 2, 'note' => 'Annullato dal cliente'])->assertOk();
        $this->get(route('pickups.index', ['month' => '2026-11']))->assertSee('data-pickup-date="2026-11-02" data-pickup-count="0"', false);
        $this->getJson(route('pickups.day', ['date' => '2026-11-02']))->assertOk()->assertJsonPath('total_orders', 0)->assertJsonCount(0, 'data');
        $this->getJson($summary->json('data.0.orders_url'))->assertNotFound();
    }

    public function test_one_account_loads_forty_seven_orders_only_in_stable_twenty_order_pages(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 10:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Rider Test Calendario']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Account cliente di riserva']);
        $orders = collect();
        for ($index = 0; $index < 47; $index++) {
            $orders->push(Order::factory()->create([
                'created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $rider->id,
                'pickup_date' => '2026-10-16', 'pickup_from' => $index % 2 === 0 ? '10:00' : '09:00',
                'store_name' => $index === 1 ? '' : 'Azienda Test Distribuzione Metropolitana', 'status' => OrderStatus::PickupScheduled,
                'created_at' => '2026-10-15 08:00:00',
            ]));
        }
        $expected = $orders->sortBy([['pickup_from', 'asc'], ['id', 'asc']])->pluck('slug')->values()->all();
        $this->actingAs($admin);
        $summaryUrl = route('pickups.day', ['date' => '2026-10-16']);
        $summary = $this->getJson($summaryUrl)->assertOk()->assertJsonPath('total_orders', 47)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.orders_count', 47)
            ->assertJsonPath('data.0.slug', $customer->slug)->assertJsonMissingPath('data.0.orders');
        $html = $this->get($summaryUrl)->assertSee($customer->name)->assertDontSee($orders->first()->reference)
            ->assertDontSee('data-operational-order=', false)->assertViewMissing('orders');
        $seen = [];
        foreach ([1 => 20, 2 => 20, 3 => 7] as $page => $count) {
            $url = route('pickups.account-orders', ['account' => $customer->slug, 'date' => '2026-10-16', 'kind' => 'customer', 'page' => $page, 'per_page' => 20]);
            $response = $this->getJson($url)->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('meta.total', 47)
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.last_page', 3);
            foreach ($response->json('data') as $item) {
                $this->assertSame(route('orders.show', $item['slug']), $item['detail_url']);
                $this->assertSame($rider->slug, $item['rider']['slug']);
            }
            $seen = [...$seen, ...array_column($response->json('data'), 'slug')];
            $this->exportPickupResponse($url, 'pickup-single-orders-'.$page.'.html', $this->get($url)->assertOk()->getContent());
        }
        $this->assertSame($expected, $seen);
        $this->assertSame(47, count(array_unique($seen)));
        $this->getJson(route('pickups.account-orders', ['account' => $customer->slug, 'date' => '2026-10-16', 'kind' => 'customer', 'page' => 4]))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 47);
        $this->get('/orders/'.$orders->first()->id)->assertNotFound();
        $this->exportPickupResponse($summaryUrl, 'pickup-single.html', $html->getContent());
        $this->exportPickupResponse($summaryUrl, 'pickup-single-accounts.html', $this->withHeader('X-Pickup-Agenda', 'accounts')->get($summaryUrl)->assertOk()->getContent());
        $this->withHeader('X-Pickup-Agenda', '');
        $this->exportPickupManifest(['single' => ['date' => '2026-10-16', 'accounts' => $summary->json('data')]]);
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            $this->patchJson('/orders/'.$orders[1]->slug, ['status' => 'cancelled', 'version' => 1, 'note' => 'Rimozione dimostrativa per aggiornamento agenda'])->assertOk();
            $changedSummary = $this->getJson($summaryUrl)->assertOk()->assertJsonPath('total_orders', 46);
            file_put_contents($directory.'/pickup-single-accounts-after-change.html', $this->withHeader('X-Pickup-Agenda', 'accounts')->get($summaryUrl)->assertOk()->getContent());
            $this->withHeader('X-Pickup-Agenda', '');
            file_put_contents($directory.'/pickup-single-orders-after-change.html', $this->get($changedSummary->json('data.0.orders_url'))->assertOk()->getContent());
            $this->exportPickupManifest(['single_after_change' => ['accounts_file' => 'pickup-single-accounts-after-change.html', 'orders_file' => 'pickup-single-orders-after-change.html', 'removed_order_slug' => $orders[1]->slug]]);
        }
    }

    public function test_account_pagination_keeps_forty_one_identities_and_all_forty_seven_orders_exactly_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Rider Test Calendario']);
        $firstCustomer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Macelleria Primo Account']);
        $firstOrders = collect();
        for ($index = 0; $index < 7; $index++) {
            $firstOrders->push(Order::factory()->create(['customer_id' => $firstCustomer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-10-17', 'pickup_from' => $index % 2 === 0 ? '10:00' : '09:00', 'status' => OrderStatus::PickupScheduled, 'created_at' => '2026-10-16 08:00:00']));
        }
        $otherOrders = collect();
        for ($index = 0; $index < 40; $index++) {
            $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => sprintf('Account %02d', $index)]);
            $otherOrders->push(Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-10-17', 'pickup_from' => '09:30', 'status' => OrderStatus::PickupScheduled]));
        }
        $this->actingAs($admin);
        $seenOrders = [];
        $seenAccounts = [];
        $accounts = [];
        foreach ([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 1] as $page => $count) {
            $url = route('pickups.day', ['date' => '2026-10-17', 'page' => $page, 'per_page' => 10]);
            $response = $this->getJson($url)->assertOk()->assertJsonCount($count, 'data')
                ->assertJsonPath('total_orders', 47)->assertJsonPath('meta.total', 41)->assertJsonPath('meta.last_page', 5)
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.per_page', 10);
            $html = $this->get($url)->assertOk()->assertDontSee('data-operational-order=', false)->assertViewMissing('orders');
            $this->exportPickupResponse($url, 'pickup-day-page-'.$page.'.html', $html->getContent());
            $fragment = $this->withHeader('X-Pickup-Agenda', 'accounts')->get($url)->assertOk();
            $this->withHeader('X-Pickup-Agenda', '');
            $this->exportPickupResponse($url, 'pickup-accounts-page-'.$page.'.html', $fragment->getContent());
            foreach ($response->json('data') as $account) {
                $this->assertSame(['slug', 'name', 'kind', 'orders_count', 'parcel_count', 'orders_url'], array_keys($account));
                $seenAccounts[] = $account['slug'];
                $accounts[] = $account;
                $ordersUrl = $account['orders_url'];
                $details = $this->getJson($ordersUrl)->assertOk()->assertJsonPath('account.slug', $account['slug']);
                $this->assertCount($account['orders_count'], $details->json('data'));
                $seenOrders = [...$seenOrders, ...array_column($details->json('data'), 'slug')];
                $this->exportPickupResponse($ordersUrl, 'pickup-orders-'.$account['slug'].'-1.html', $this->get($ordersUrl)->assertOk()->getContent());
            }
        }
        $this->assertSame(41, count(array_unique($seenAccounts)));
        $this->assertSame(47, count(array_unique($seenOrders)));
        $expected = $firstOrders->sortBy([['pickup_from', 'asc'], ['id', 'asc']])->pluck('slug')->concat($otherOrders->pluck('slug'))->all();
        $this->assertSame($expected, $seenOrders);
        $this->get(route('pickups.index', ['month' => '2026-10']))->assertSee('data-pickup-date="2026-10-17" data-pickup-count="47"', false);
        $this->getJson(route('pickups.day', ['date' => '2026-10-17', 'page' => 6]))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('total_orders', 47)->assertJsonPath('meta.total', 41);
        $this->exportPickupManifest(['date' => '2026-10-17', 'accounts' => $accounts]);
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            foreach (['2026-09', '2026-10', '2026-11'] as $month) {
                file_put_contents($directory.'/pickup-calendar-'.$month.'.html', $this->get(route('pickups.index', ['month' => $month]))->assertOk()->getContent());
            }
            file_put_contents($directory.'/pickup-day-empty.html', $this->get(route('pickups.day', ['date' => '2026-10-18']))->assertOk()->getContent());
        }
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            foreach ($firstOrders as $order) {
                $this->patchJson('/orders/'.$order->slug, ['status' => 'cancelled', 'version' => 1, 'note' => 'Rimozione dimostrativa account agenda'])->assertOk();
            }
            $summaryUrl = route('pickups.day', ['date' => '2026-10-17']);
            $this->getJson($summaryUrl)->assertOk()->assertJsonPath('total_orders', 40)->assertJsonPath('meta.total', 40);
            file_put_contents($directory.'/pickup-accounts-after-removal.html', $this->withHeader('X-Pickup-Agenda', 'accounts')->get($summaryUrl)->assertOk()->getContent());
            $this->withHeader('X-Pickup-Agenda', '');
            file_put_contents($directory.'/pickup-day-after-removal.html', $this->get($summaryUrl)->assertOk()->getContent());
            $this->exportPickupManifest(['after_removal' => ['accounts_file' => 'pickup-accounts-after-removal.html', 'full_file' => 'pickup-day-after-removal.html', 'missing_slug' => $firstCustomer->slug]]);
        }

    }

    public function test_calendar_and_daily_report_keep_the_staff_visibility_boundary(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $own = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::PickupScheduled, 'pickup_date' => '2026-10-14']);
        Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Accepted, 'pickup_date' => '2026-10-14']);
        Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Received, 'pickup_date' => '2026-10-14']);
        $this->get(route('pickups.index'))->assertRedirect(route('login'));
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertRedirect(route('login'));
        $this->actingAs($customer)->get(route('pickups.index'))->assertForbidden();
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertForbidden();
        $this->actingAs($rider)->get(route('pickups.index', ['month' => '2026-10']))->assertSee('data-pickup-date="2026-10-14" data-pickup-count="1"', false);
        $summary = $this->getJson(route('pickups.day', ['date' => '2026-10-14']))->assertOk()->assertJsonPath('total_orders', 1);
        $this->getJson($summary->json('data.0.orders_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $own->slug);
        $this->actingAs($admin)->getJson(route('pickups.day', ['date' => '2026-10-14']))->assertOk()->assertJsonPath('total_orders', 3);
    }

    public function test_account_identity_keeps_equal_names_and_manual_senders_in_separate_groups(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(2)->create(['role' => UserRole::Customer, 'name' => 'Nome attività condiviso']);
        $first = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customers[0]->id, 'store_name' => 'Mittente storico', 'pickup_date' => '2026-11-06', 'pickup_address' => 'Via Primo indirizzo']);
        $second = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customers[0]->id, 'store_name' => 'Mittente modificato', 'pickup_date' => '2026-11-06', 'pickup_address' => 'Via Secondo indirizzo']);
        $other = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customers[1]->id, 'store_name' => 'Mittente storico', 'pickup_date' => '2026-11-06']);
        $manual = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => null, 'store_name' => 'Nome attività condiviso', 'pickup_date' => '2026-11-06']);

        $response = $this->actingAs($admin)->getJson(route('pickups.day', ['date' => '2026-11-06']))->assertOk();

        $response->assertJsonPath('total_orders', 4)->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.slug', $customers[0]->slug)->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.1.slug', $customers[1]->slug)->assertJsonPath('data.2.slug', $manual->slug)
            ->assertJsonPath('data.2.kind', 'manual');
        foreach ([[$first->slug, $second->slug], [$other->slug], [$manual->slug]] as $index => $slugs) {
            $details = $this->getJson($response->json('data.'.$index.'.orders_url'))->assertOk();
            $this->assertSame($slugs, array_column($details->json('data'), 'slug'));
        }
    }

    public function test_orders_with_the_same_pickup_time_follow_creation_chronology_inside_the_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $later = Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-06', 'pickup_from' => '09:00', 'created_at' => '2026-11-05 12:00:00']);
        $earlier = Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-06', 'pickup_from' => '09:00', 'created_at' => '2026-11-05 08:00:00']);

        $summary = $this->actingAs($admin)->getJson(route('pickups.day', ['date' => '2026-11-06']))->assertOk();
        $response = $this->getJson($summary->json('data.0.orders_url'))->assertOk();

        $this->assertSame([$earlier->slug, $later->slug], array_column($response->json('data'), 'slug'));
        $this->get($summary->json('data.0.orders_url'))->assertSeeInOrder([$earlier->slug, $later->slug], false);
    }

    public function test_invalid_calendar_inputs_are_rejected_and_legacy_modes_do_not_open_a_second_agenda(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        foreach (['2026-13', '2026-2', '0000-01', '2026-10-14', 'bad'] as $month) {
            $this->getJson(route('pickups.index', ['month' => $month]))->assertUnprocessable()->assertJsonValidationErrors('month');
        }
        foreach (['2026-02-29', '2026-10-32', '0000-01-01', '14/10/2026', ''] as $date) {
            $this->getJson(route('pickups.day', ['date' => $date]))->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        $this->getJson(route('pickups.day', ['date' => '2026-10-14', 'page' => 0]))->assertUnprocessable();
        $this->get(route('pickups.index', ['view' => 'date', 'date' => '2026-10-14']))->assertOk()->assertViewIs('pickups.index')->assertDontSee('Vista raggruppata');
        $this->get('/pickups/grouped')->assertNotFound();
    }

    private function exportPickupResponse(string $url, string $file, string $content): void
    {
        if (! $directory = getenv('EA_UI_FIXTURE_DIR')) {
            return;
        }
        file_put_contents($directory.'/'.$file, $content);
        $path = parse_url($url, PHP_URL_PATH);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $query['page'] ??= 1;
        $query['per_page'] ??= str_contains($path, '/accounts/') ? 20 : 10;
        ksort($query);
        $this->exportPickupManifest(['responses' => [$path.'?'.http_build_query($query) => $file]]);
    }

    /** @param array<string,mixed> $values */
    private function exportPickupManifest(array $values): void
    {
        if (! $directory = getenv('EA_UI_FIXTURE_DIR')) {
            return;
        }
        $path = $directory.'/pickup-agenda-manifest.json';
        $manifest = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        $manifest = array_replace_recursive($manifest, $values);
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
