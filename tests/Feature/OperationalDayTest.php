<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OperationalDayTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([20, 0])]
    #[TestWith([16, 4])]
    public function test_daily_counts_and_late_completion_stay_in_the_programmed_day(int $completed, int $remaining): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $orders = Order::factory()->count(20)->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::OutForDelivery, 'price_cents' => 500, 'estimated_delivery_to' => '2026-10-07']);
        foreach ($orders->take($completed) as $order) {
            $order->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => now()])->save();
        }
        Order::factory()->count(12)->create(['created_by' => $admin->id, 'pickup_date' => '2026-10-08']);
        $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonPath('summary.total', 20)->assertJsonPath('summary.delivered', $completed)->assertJsonPath('summary.undelivered', $remaining)->assertJsonPath('summary.overdue', 0);
        $this->getJson('/rider-operations/feed?date=2026-10-08')->assertJsonPath('summary.total', 12);
        if ($remaining) {
            $order = $orders->last();
            $this->get('/rider-operations/detail?date=2026-10-07')->assertSee('Consegnata oltre il termine previsto')->assertSee('07/10/2026 23:59');
            $directory = getenv('EA_UI_FIXTURE_DIR');
            if ($directory) {
                file_put_contents($directory.'/late-day.html', $this->get('/rider-operations?date=2026-10-07')->getContent());
                file_put_contents($directory.'/late-before.json', $this->getJson('/rider-operations/feed?date=2026-10-07')->getContent());
                file_put_contents($directory.'/late-orders-before.html', $this->get('/rider-operations/detail?date=2026-10-07')->getContent());
            }
            $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Europe/Rome'));
            $this->patchJson('/orders/'.$order->slug, ['status' => 'delivered', 'version' => 1])->assertOk();
            $this->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonPath('summary.total', 20)->assertJsonPath('summary.delivered', 17)->assertJsonPath('summary.undelivered', 3)->assertJsonPath('summary.overdue', 0);
            $this->getJson('/rider-operations/feed?date=2026-10-09')->assertJsonPath('summary.total', 0);
            $this->get('/rider-operations/detail?date=2026-10-07')->assertSee('09/10/2026 14:00')->assertSee('Consegnata oltre il termine previsto');
            if ($directory) {
                file_put_contents($directory.'/late-after.json', $this->getJson('/rider-operations/feed?date=2026-10-07')->getContent());
                file_put_contents($directory.'/late-orders-after.html', $this->get('/rider-operations/detail?date=2026-10-07')->getContent());
            }
        }
    }

    public function test_official_pickup_rescheduling_moves_only_the_explicit_schedule_and_keeps_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 08:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::Accepted, 'rider_id' => $admin->id]);
        $this->actingAs($admin)->patchJson('/orders/'.$order->slug.'/pickup-schedule', ['pickup_date' => '2026-10-08', 'pickup_from' => '10:00', 'pickup_to' => '12:00', 'reason' => 'Cambio concordato', 'version' => 1])->assertOk();
        $this->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonPath('summary.total', 0)->assertJsonPath('schedule_history.0.before.pickup_date', '2026-10-07')->assertJsonPath('schedule_history.0.after.pickup_date', '2026-10-08')->assertJsonPath('schedule_history.0.reference', $order->reference)->assertJsonPath('schedule_history.0.url', route('orders.show', $order));
        $this->getJson('/rider-operations/feed?date=2026-10-08')->assertJsonPath('summary.total', 1);
        $this->assertSame('Cambio concordato', $order->events()->sole()->note);
    }

    public function test_confirmed_eta_survives_the_next_transition_without_changing_the_programmed_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::DeliveryAttempted, 'price_cents' => 500]);
        $order->events()->create(['user_id' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::PickedUp]);
        $this->actingAs($admin)->patchJson('/orders/'.$order->slug, ['status' => 'rescheduled', 'version' => 1, 'estimated_at' => '2026-10-08T10:00', 'note' => 'Concordato con destinatario'])->assertOk();
        $this->patchJson('/orders/'.$order->slug, ['status' => 'out_for_delivery', 'version' => 2])->assertOk();
        $this->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonPath('summary.overdue', 0);
        $this->travelTo(Carbon::parse('2026-10-08 10:01', 'Europe/Rome'));
        $this->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonPath('summary.overdue', 0);
        $this->getJson('/rider-operations/feed?date=2026-10-08')->assertJsonPath('summary.overdue', 1)->assertJsonPath('summary.backlog', 1);
        $this->getJson('/rider-operations/feed?date=2026-10-08')->assertJsonPath('summary.total', 0);
        $this->get('/rider-operations/detail?date=2026-10-07')->assertSee('08/10/2026 10:00');
    }

    public function test_deadline_is_not_invented_and_closed_failures_are_not_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach ([OrderStatus::Received, OrderStatus::Rejected, OrderStatus::Cancelled] as $status) {
            Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-10-07', 'status' => $status, 'delivery_window' => '09:00–10:00']);
        }
        $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonPath('summary.total', 3)->assertJsonPath('summary.undelivered', 3)->assertJsonPath('summary.overdue', 0)->assertJsonPath('summary.closed_unfulfilled', 2);
    }

    public function test_detail_filters_accept_only_public_rider_slugs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->actingAs($admin)->getJson('/rider-operations/detail?rider='.$rider->id)->assertUnprocessable()->assertJsonValidationErrors('rider');
        $this->get('/rider-operations/detail?rider='.$rider->slug)->assertOk();
    }

    public function test_rome_midnight_and_dst_use_the_local_deadline(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 23:59:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => '2026-10-25', 'status' => OrderStatus::OutForDelivery, 'estimated_delivery_to' => '2026-10-25']);
        $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-10-25')->assertJsonPath('summary.overdue', 0);
        $this->travelTo(Carbon::parse('2026-10-26 00:00:01', 'Europe/Rome'));
        $this->getJson('/rider-operations/feed?date=2026-10-25')->assertJsonPath('summary.total', 1)->assertJsonPath('summary.overdue', 0);
        $this->getJson('/rider-operations/feed?date=2026-10-26')->assertJsonPath('summary.total', 0)->assertJsonPath('summary.overdue', 1)->assertJsonPath('summary.backlog', 1);
    }

    #[TestWith([5])]
    #[TestWith([15])]
    #[TestWith([30])]
    #[TestWith([50])]
    public function test_many_zones_keep_queries_bounded_and_details_paginated(int $zones): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $riders = User::factory()->count($zones)->create();
        foreach ($riders as $index => $rider) {
            Order::factory()->count(25)->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'pickup_date' => '2026-10-07', 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Zona '.sprintf('%02d', $index), 'price_cents' => 500]);
        }
        $this->actingAs($admin);
        DB::enableQueryLog();
        $feed = $this->getJson('/rider-operations/feed?date=2026-10-07')->assertJsonCount($zones, 'zones')->assertJsonCount($zones, 'riders')->assertJsonPath('summary.total', $zones * 25)->assertJsonMissingPath('locations')->assertJsonMissingPath('orders');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(15, count($queries));
        $detail = $this->get('/rider-operations/detail?date=2026-10-07&zone=Zona+00')->assertViewHas('orders', fn ($orders) => $orders->count() === 20 && $orders->total() === 25);
        $this->get('/rider-operations/detail?date=2026-10-07&zone=Zona+00&page=2')->assertViewHas('orders', fn ($orders) => $orders->count() === 5);
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if ($directory) {
            file_put_contents($directory.'/zones-'.$zones.'.html', $this->get('/rider-operations?date=2026-10-07')->getContent());
            file_put_contents($directory.'/zones-'.$zones.'.json', $feed->getContent());
            file_put_contents($directory.'/zone-detail-'.$zones.'.html', $detail->getContent());
            file_put_contents($directory.'/zone-detail-page-2.html', $this->get('/rider-operations/detail?date=2026-10-07&zone=Zona+00&page=2')->getContent());
        }
        Order::where('rider_id', $riders->first()->id)->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        $this->get('/rider-operations/'.$riders->first()->slug.'?date=2026-10-07')->assertViewHas('deliveredCount', 25)->assertViewHas('orders', fn ($orders) => $orders->count() === 10 && $orders->total() === 25)->assertViewHas('remaining', 0);

    }
}
