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
        $response = $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertOk();
        $this->assertSame(6, $response->viewData('totalOrders'));
        $this->assertSame(6, substr_count($response->getContent(), 'class="surface pickup-order-card"'));
        $this->get(route('pickups.day', ['date' => '2026-10-15']))->assertOk()->assertSee('Nessun ritiro da effettuare');
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

    public function test_daily_queries_do_not_grow_with_the_number_of_customer_and_rider_cards(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['created_by' => $admin->id, 'customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'rider_id' => $admin->id, 'pickup_date' => '2026-10-14']);
        $this->actingAs($admin);
        DB::enableQueryLog();
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertOk();
        $singleCardQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 19; $index++) {
            Order::factory()->create(['created_by' => $admin->id, 'customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'rider_id' => User::factory()->create(['role' => UserRole::Rider])->id, 'pickup_date' => '2026-10-14']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertOk();
        $this->assertLessThanOrEqual($singleCardQueries + 2, count(DB::getQueryLog()), 'Rendering twenty cards must batch customer and Rider queries.');
        DB::disableQueryLog();
    }

    public function test_official_rescheduling_and_cancellation_update_both_views(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 08:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Accepted, 'pickup_date' => '2026-10-08']);
        $this->actingAs($admin)->patchJson(route('orders.pickup-schedule', $order), [
            'pickup_date' => '2026-11-02', 'pickup_from' => '10:00', 'pickup_to' => '12:00', 'reason' => 'Cambio concordato', 'version' => 1,
        ])->assertOk();
        $this->get(route('pickups.index', ['month' => '2026-10']))->assertOk()->assertSee('data-pickup-date="2026-10-08" data-pickup-count="0"', false);
        $this->get(route('pickups.index', ['month' => '2026-11']))->assertOk()->assertSee('data-pickup-date="2026-11-02" data-pickup-count="1"', false);
        $this->get(route('pickups.day', ['date' => '2026-10-08']))->assertOk()->assertSee('Nessun ritiro da effettuare');
        $this->get(route('pickups.day', ['date' => '2026-11-02']))->assertOk()->assertSee($order->reference);
        $this->patchJson('/orders/'.$order->slug, ['status' => 'cancelled', 'version' => 2, 'note' => 'Annullato dal cliente'])->assertOk();
        $this->get(route('pickups.index', ['month' => '2026-11']))->assertOk()->assertSee('data-pickup-date="2026-11-02" data-pickup-count="0"', false);
        $this->get(route('pickups.day', ['date' => '2026-11-02']))->assertOk()->assertSee('Nessun ritiro da effettuare');
    }

    public function test_daily_report_keeps_all_forty_seven_orders_of_one_account_in_stable_time_order(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Rider Test Calendario']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Account cliente di riserva']);
        $orders = collect();
        for ($index = 0; $index < 47; $index++) {
            $orders->push(Order::factory()->create([
                'created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $rider->id,
                'pickup_date' => '2026-10-17', 'pickup_from' => $index % 2 === 0 ? '10:00' : '09:00',
                'store_name' => $index === 1 ? '' : 'Azienda Test Distribuzione Metropolitana', 'status' => OrderStatus::PickupScheduled,
            ]));
        }
        $expected = $orders->sortBy([['pickup_from', 'asc'], ['id', 'asc']])->pluck('slug')->values();
        $response = $this->actingAs($admin)->get(route('pickups.day', ['date' => '2026-10-17']))->assertOk();
        $response->assertViewHas('totalOrders', 47)->assertSee($customer->name)->assertSee('47 ordini in questa pagina')
            ->assertSee('Rider Test Calendario')->assertSee(route('orders.show', $orders->first()), false)
            ->assertSee('data-pickup-account="'.$customer->slug.'"', false)->assertDontSee('Raggruppa per cliente');
        $this->assertSame(1, $response->viewData('groups')->total());
        $this->assertSame($expected->all(), $response->viewData('groups')->first()->pluck('slug')->all());
        $this->assertSame(47, substr_count($response->getContent(), 'class="surface pickup-order-card"'));
        $this->get(route('pickups.day', ['date' => '2026-10-17', 'page' => 2]))->assertOk()
            ->assertSee('Nessun ritiro in questa pagina')->assertSee('Sono presenti 47 ritiri nella giornata.')->assertDontSee('Pagina 2 di 1');
        $this->get('/orders/'.$orders->first()->id)->assertNotFound();
    }

    public function test_daily_pagination_keeps_account_groups_whole_and_contains_every_order_exactly_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Rider Test Calendario']);
        $firstCustomer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Macelleria Primo Account']);
        $firstOrders = collect();
        for ($index = 0; $index < 7; $index++) {
            $firstOrders->push(Order::factory()->create(['customer_id' => $firstCustomer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-10-17', 'pickup_from' => $index % 2 === 0 ? '10:00' : '09:00', 'status' => OrderStatus::PickupScheduled]));
        }
        $otherOrders = collect();
        for ($index = 0; $index < 40; $index++) {
            $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => sprintf('Account %02d', $index)]);
            $otherOrders->push(Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-10-17', 'pickup_from' => '09:30', 'status' => OrderStatus::PickupScheduled]));
        }
        $this->actingAs($admin);
        $seenOrders = collect();
        $seenAccounts = collect();
        foreach ([1 => 26, 2 => 20, 3 => 1] as $page => $count) {
            $response = $this->get(route('pickups.day', ['date' => '2026-10-17', 'page' => $page]))->assertOk();
            $groups = $response->viewData('groups');
            $response->assertViewHas('totalOrders', 47)->assertSee('Pagina '.$page.' di 3')->assertSee('date=2026-10-17&amp;page=', false)
                ->assertSee('Rider Test Calendario');
            $this->assertSame(41, $groups->total());
            $this->assertSame($count, $response->viewData('orders')->count());
            foreach ($groups as $group) {
                $this->assertSame(1, $group->pluck('customer_id')->unique()->count());
                $seenAccounts->push($group->first()->customer->slug);
                $seenOrders = $seenOrders->concat($group->pluck('slug'));
                foreach ($group as $order) {
                    $response->assertSee('name="ids[]" value="'.$order->slug.'"', false)->assertSee(route('orders.show', $order), false);
                }
            }
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/pickup-day-page-'.$page.'.html', $response->getContent());
            }
        }
        $this->assertSame(41, $seenAccounts->unique()->count());
        $this->assertSame(47, $seenOrders->unique()->count());
        $expected = $firstOrders->sortBy([['pickup_from', 'asc'], ['id', 'asc']])->pluck('slug')->concat($otherOrders->pluck('slug'));
        $this->assertSame($expected->all(), $seenOrders->all());
        $this->get(route('pickups.index', ['month' => '2026-10']))->assertSee('data-pickup-date="2026-10-17" data-pickup-count="47"', false);
        $this->get(route('pickups.day', ['date' => '2026-10-17', 'page' => 4]))->assertOk()
            ->assertSee('Nessun ritiro in questa pagina')->assertSee('Sono presenti 47 ritiri nella giornata.')->assertDontSee('Pagina 4 di 3');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            foreach (['2026-09', '2026-10', '2026-11'] as $month) {
                file_put_contents($directory.'/pickup-calendar-'.$month.'.html', $this->get(route('pickups.index', ['month' => $month]))->assertOk()->getContent());
            }
            file_put_contents($directory.'/pickup-day-empty.html', $this->get(route('pickups.day', ['date' => '2026-10-18']))->assertOk()->getContent());
        }
    }

    public function test_calendar_and_daily_report_keep_the_staff_visibility_boundary(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $own = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::PickupScheduled, 'pickup_date' => '2026-10-14']);
        $other = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Accepted, 'pickup_date' => '2026-10-14']);
        Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Received, 'pickup_date' => '2026-10-14']);
        $this->get(route('pickups.index'))->assertRedirect(route('login'));
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertRedirect(route('login'));
        $this->actingAs($customer)->get(route('pickups.index'))->assertForbidden();
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertForbidden();
        $this->actingAs($rider)->get(route('pickups.index', ['month' => '2026-10']))->assertOk()->assertSee('data-pickup-date="2026-10-14" data-pickup-count="1"', false);
        $this->get(route('pickups.day', ['date' => '2026-10-14']))->assertOk()->assertSee($own->reference)->assertDontSee($other->reference);
        $this->actingAs($admin)->get(route('pickups.day', ['date' => '2026-10-14']))->assertOk()->assertViewHas('totalOrders', 3);
    }

    public function test_account_identity_keeps_equal_names_and_manual_senders_in_separate_groups(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customers = User::factory()->count(2)->create(['role' => UserRole::Customer, 'name' => 'Nome attività condiviso']);
        $first = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customers[0]->id, 'store_name' => 'Mittente storico', 'pickup_date' => '2026-11-06', 'pickup_address' => 'Via Primo indirizzo']);
        $second = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customers[0]->id, 'store_name' => 'Mittente modificato', 'pickup_date' => '2026-11-06', 'pickup_address' => 'Via Secondo indirizzo']);
        $other = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => $customers[1]->id, 'store_name' => 'Mittente storico', 'pickup_date' => '2026-11-06']);
        $manual = Order::factory()->create(['created_by' => $admin->id, 'customer_id' => null, 'store_name' => 'Nome attività condiviso', 'pickup_date' => '2026-11-06']);

        $response = $this->actingAs($admin)->get(route('pickups.day', ['date' => '2026-11-06']))->assertOk();

        $response->assertViewHas('totalOrders', 4)->assertSee('data-pickup-account="'.$customers[0]->slug.'"', false)
            ->assertSee('data-pickup-account="'.$customers[1]->slug.'"', false)->assertSee('data-pickup-account="'.$manual->slug.'"', false);
        $groups = $response->viewData('groups');
        $this->assertSame(3, $groups->total());
        $this->assertSame([$first->slug, $second->slug], $groups->items()[0]->pluck('slug')->all());
        $this->assertSame([$other->slug], $groups->items()[1]->pluck('slug')->all());
        $this->assertSame([$manual->slug], $groups->items()[2]->pluck('slug')->all());
    }

    public function test_orders_with_the_same_pickup_time_follow_creation_chronology_inside_the_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $later = Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-06', 'pickup_from' => '09:00', 'created_at' => '2026-11-05 12:00:00']);
        $earlier = Order::factory()->create(['customer_id' => $customer->id, 'pickup_date' => '2026-11-06', 'pickup_from' => '09:00', 'created_at' => '2026-11-05 08:00:00']);

        $response = $this->actingAs($admin)->get(route('pickups.day', ['date' => '2026-11-06']))->assertOk();

        $this->assertSame([$earlier->slug, $later->slug], $response->viewData('groups')->first()->pluck('slug')->all());
        $response->assertSeeInOrder([$earlier->reference, $later->reference]);
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
}
