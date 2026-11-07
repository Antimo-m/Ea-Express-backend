<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RiderWorkspaceRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'Europe/Rome'));
        if (! getenv('EA_RIDER_FIXTURE_DIR')) {
            $this->withoutVite();
        }
    }

    public function test_rider_range_includes_both_boundaries_and_retains_its_context_across_pages_and_states(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        Order::factory()->count(21)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-15', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::Accepted]);
        $last = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-17', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::Delivered]);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-14', 'delivery_zone' => 'Napoli', 'store_name' => 'Prima del periodo']);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-18', 'delivery_zone' => 'Napoli', 'store_name' => 'Dopo il periodo']);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-16', 'delivery_zone' => 'Caserta', 'store_name' => 'Altra zona']);
        $path = '/rider-operations/'.$rider->slug.'?period=custom&from=2026-09-15&to=2026-09-17&zone=Napoli';

        $this->actingAs($admin)->get($path)->assertOk()
            ->assertViewHas('counts', fn ($counts) => $counts['total'] === 22 && $counts['completed'] === 1)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 20 && $orders->total() === 22)
            ->assertSee('from=2026-09-15', false)->assertSee('to=2026-09-17', false)->assertSee('zone=Napoli', false)
            ->assertSee(route('users.index'))->assertDontSee('Prima del periodo')->assertDontSee('Dopo il periodo')->assertDontSee('Altra zona');
        $this->get($path.'&page=2')->assertViewHas('orders', fn ($orders) => $orders->count() === 2 && $orders->contains('slug', $last->slug));
        $this->get($path.'&state=completed')->assertViewHas('orders', fn ($orders) => $orders->pluck('slug')->all() === [$last->slug])
            ->assertViewHas('counts', fn ($counts) => $counts['total'] === 22);
        $this->get('/rider-operations/'.$rider->slug.'?from=2026-09-17&to=2026-09-17')->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('slug')->all() === [$last->slug]);
        $this->getJson('/rider-operations/'.$rider->slug.'?from=2026-09-17&to=2026-09-15')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/rider-operations/'.$rider->slug.'?from=2026-09-17')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_range_accounting_includes_final_day_payments_expenses_and_open_positions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-15']);
        foreach ([['2026-09-14 23:59', 99000], ['2026-09-15 00:00', 100], ['2026-09-17 23:59', 200], ['2026-09-18 00:00', 99000]] as [$date, $amount]) {
            $entry = new PaymentEntry;
            $entry->order_id = $order->id;
            $entry->user_id = $admin->id;
            $entry->amount_cents = $amount;
            $entry->note = 'Incasso di prova';
            $entry->created_at = Carbon::parse($date, 'Europe/Rome')->utc();
            $entry->save();
        }
        foreach ([['2026-09-14', 99000], ['2026-09-15', 10], ['2026-09-17', 20], ['2026-09-18', 99000]] as [$date, $amount]) {
            Expense::factory()->create(['user_id' => $rider->id, 'spent_on' => $date, 'amount_cents' => $amount]);
            PendingAccount::factory()->create(['order_id' => Order::factory()->create(['rider_id' => $rider->id])->id, 'occurred_on' => $date, 'direction' => 'incoming', 'amount_cents' => $amount, 'settled_cents' => 0, 'state' => 'open']);
        }

        $this->actingAs($admin)->get('/rider-operations/'.$rider->slug.'?from=2026-09-15&to=2026-09-17')->assertOk()
            ->assertViewHas('accounting', fn ($data) => $data['gross'] === 300 && $data['expenses'] === 30 && $data['balance'] === 270 && $data['pending_incoming'] === 30);
    }

    public function test_explorer_uses_real_open_statuses_and_public_rider_links_preserve_the_entire_period(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        foreach ([[OrderStatus::Delivered, $rider->id], [OrderStatus::Accepted, $rider->id], [OrderStatus::Received, null], [OrderStatus::Cancelled, null], [OrderStatus::Rejected, $rider->id]] as [$status, $riderId]) {
            Order::factory()->create(['pickup_date' => '2026-09-17', 'status' => $status, 'rider_id' => $riderId, 'delivery_zone' => 'Napoli']);
        }

        $feed = $this->actingAs($admin)->getJson('/rider-operations/feed?from=2026-09-15&to=2026-09-17')->assertOk()
            ->assertJsonPath('summary.total', 5)->assertJsonPath('summary.delivered', 1)->assertJsonPath('summary.pending', 1)->assertJsonPath('summary.unassigned', 1)
            ->assertJsonPath('zones.0.pending', 1)->assertJsonPath('zones.0.unassigned', 1)->assertJsonMissingPath('summary.overdue')->assertJsonMissingPath('summary.backlog');
        $expected = route('rider-operations.show', ['rider' => $rider->slug, 'from' => '2026-09-15', 'to' => '2026-09-17', 'period' => 'custom']);
        $this->assertSame($expected, $feed->json('zones.0.riders.0.url'));
        $this->assertSame($expected, collect($feed->json('riders'))->firstWhere('slug', $rider->slug)['url']);
        $this->get('/rider-operations?from=2026-09-15&to=2026-09-17')->assertOk()->assertDontSee('value="volume"', false)->assertDontSee('Ritardi')->assertSee('data-operations-refresh', false);
    }

    public function test_browser_fixtures_include_real_rider_forms_ranges_and_counters(): void
    {
        if (! $directory = getenv('EA_RIDER_FIXTURE_DIR')) {
            $this->markTestSkipped('Browser fixtures are generated only when requested by the browser suite.');
        }
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['name' => 'Rider Alfa']);
        $other = User::factory()->create(['name' => 'Rider Beta']);
        Order::factory()->count(21)->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-15', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::Accepted, 'store_name' => 'Prima giornata']);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-09-17', 'delivery_zone' => 'Caserta', 'status' => OrderStatus::Delivered, 'store_name' => 'Ultima giornata']);
        Order::factory()->count(4)->create(['rider_id' => $other->id, 'pickup_date' => '2026-09-16', 'delivery_zone' => 'Caserta', 'status' => OrderStatus::Delivered]);
        Order::factory()->count(3)->create(['rider_id' => null, 'pickup_date' => '2026-09-16', 'delivery_zone' => 'Aversa', 'status' => OrderStatus::Received]);
        Order::factory()->create(['rider_id' => null, 'pickup_date' => '2026-09-16', 'delivery_zone' => 'Aversa', 'status' => OrderStatus::Cancelled]);
        Order::factory()->create(['rider_id' => $other->id, 'pickup_date' => '2026-09-16', 'delivery_zone' => 'Caserta', 'status' => OrderStatus::Rejected]);
        Order::factory()->create(['rider_id' => $rider->id, 'pickup_date' => '2026-11-06', 'delivery_zone' => 'Napoli', 'status' => OrderStatus::Accepted, 'store_name' => 'Ordine di oggi']);
        $this->actingAs($admin);
        $base = '/rider-operations/'.$rider->slug;
        $range = '?period=custom&from=2026-09-15&to=2026-09-17';
        $pages = ['day-range.html' => $base.$range, 'day-page2.html' => $base.$range.'&page=2', 'day-completed.html' => $base.$range.'&state=completed', 'day-same.html' => $base.'?period=custom&from=2026-09-17&to=2026-09-17', 'day-today.html' => $base, 'index.html' => '/rider-operations'.$range, 'create.html' => route('users.create'), 'users.html' => route('users.index')];
        foreach ($pages as $file => $url) {
            file_put_contents($directory.'/'.$file, $this->get($url)->assertOk()->getContent());
        }
        file_put_contents($directory.'/feed.json', $this->getJson('/rider-operations/feed'.$range)->assertOk()->getContent());
        foreach (['Napoli', 'Aversa', 'Caserta'] as $zone) {
            file_put_contents($directory.'/detail-'.$zone.'.html', $this->get('/rider-operations/detail'.$range.'&zone='.$zone)->assertOk()->getContent());
        }
        file_put_contents($directory.'/metadata.json', json_encode(['rider' => $rider->slug, 'from' => '2026-09-15', 'to' => '2026-09-17'], JSON_THROW_ON_ERROR));
    }
}
