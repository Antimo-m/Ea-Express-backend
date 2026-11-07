<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\DeliveryDeadline;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OperationalRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function order(User $admin, OrderStatus $status = OrderStatus::OutForDelivery): Order
    {
        $order = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'created_at' => Carbon::parse('2026-09-27 10:00', 'Europe/Rome'), 'updated_at' => Carbon::parse('2026-09-27 10:00', 'Europe/Rome'), 'pickup_date' => '2026-09-28', 'estimated_delivery_to' => '2026-09-28', 'status' => $status, 'store_name' => 'Negozio Rossi', 'recipient_name' => 'Destinatario Test Nominativo Esteso']);
        $order->events()->make(['user_id' => $admin->id, 'rider_id' => $admin->id, 'status' => $status])->forceFill(['created_at' => Carbon::parse('2026-09-27 10:00', 'Europe/Rome')->utc()])->save();

        return $order;
    }

    public function test_unfinished_orders_and_later_completions_remain_in_the_programmed_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        $order = $this->order($admin);
        $this->actingAs($admin)->getJson('/rider-operations/feed?period=today')->assertJsonPath('summary.total', 0)->assertJsonMissingPath('summary.overdue')->assertJsonMissingPath('summary.backlog');
        $this->getJson('/rider-operations/feed?date=2026-09-28')->assertJsonPath('summary.total', 1)->assertJsonPath('summary.pending', 1);
        $order->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => now()])->save();
        $order->events()->create(['user_id' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Delivered]);
        $this->getJson('/rider-operations/feed?period=today')->assertJsonPath('summary.total', 0);
        $this->getJson('/rider-operations/feed?date=2026-09-28')->assertJsonPath('summary.total', 1)->assertJsonPath('summary.delivered', 1)->assertJsonPath('summary.pending', 0);
        $this->get('/rider-operations/detail?date=2026-09-28')->assertSee('28/09/2026 23:59')->assertSee('06/10/2026 12:00');
    }

    #[TestWith(['accepted'])]
    #[TestWith(['pickup_scheduled'])]
    #[TestWith(['rider_arriving'])]
    #[TestWith(['picked_up'])]
    #[TestWith(['in_transit'])]
    #[TestWith(['out_for_delivery'])]
    #[TestWith(['delivery_issue'])]
    #[TestWith(['delivery_attempted'])]
    public function test_all_open_workflow_states_are_counted_only_in_the_programmed_period(string $status): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        $order = $this->order($admin, OrderStatus::from($status));
        $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-09-28')->assertJsonPath('summary.pending', 1)->assertJsonPath('summary.active', 1)->assertJsonPath('summary.riders', 1)->assertJsonPath('summary.zones', 1);
        $this->getJson('/rider-operations/feed?period=today')->assertJsonPath('summary.total', 0);
    }

    #[TestWith(['delivered'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['rejected'])]
    public function test_terminal_states_are_not_pending_or_awaiting_assignment(string $status): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        $this->order($admin, OrderStatus::from($status));
        $this->actingAs($admin)->getJson('/rider-operations/feed?date=2026-09-28')->assertJsonPath('summary.total', 1)->assertJsonPath('summary.active', 0)->assertJsonPath('summary.pending', 0)->assertJsonPath('summary.unassigned', 0);
    }

    public function test_authorized_eta_and_original_deadline_survive_later_updates(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 10:00', 'Europe/Rome'));
        $admin = $this->admin();
        $order = $this->order($admin, OrderStatus::DeliveryIssue);
        $order->events()->create(['user_id' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::PickedUp]);
        $this->actingAs($admin)->patchJson('/orders/'.$order->slug, ['version' => 1, 'status' => 'rescheduled', 'estimated_at' => '2026-10-02T16:00', 'note' => 'Accordo cliente'])->assertOk();
        $this->assertSame('2026-09-28T21:59:59.999999Z', $order->events()->latest('id')->first()->schedule_change['before']['deadline_at']);
        $this->patchJson('/orders/'.$order->slug, ['version' => 2, 'status' => 'out_for_delivery'])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-02 16:01', 'Europe/Rome'));
        $this->getJson('/rider-operations/feed')->assertJsonPath('summary.total', 0);
        $this->getJson('/rider-operations/feed?date=2026-09-28')->assertJsonPath('summary.total', 1);
        $this->get('/rider-operations/detail?date=2026-09-28')->assertSee('28/09/2026 23:59');
        $this->assertSame('02/10/2026 16:00', app(DeliveryDeadline::class)->forOrder($order->fresh())['label']);
    }

    public function test_orders_without_delivery_dates_still_render_without_monitoring_warnings(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        $order = $this->order($admin);
        $order->forceFill(['estimated_delivery_to' => null])->save();
        $this->actingAs($admin)->getJson('/rider-operations/feed')->assertJsonPath('summary.total', 1)->assertJsonMissingPath('summary.missing_deadlines');
        $this->get('/rider-operations')->assertOk()->assertDontSee('non hanno un termine previsto');
        $this->get('/rider-operations/'.$admin->slug)->assertOk()->assertSee('Negozio Rossi')->assertDontSee('privi di un termine previsto');
    }

    public function test_order_navigation_restores_operational_filters_and_rejects_external_return_urls(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        $order = $this->order($admin);
        $context = '/rider-operations?period=custom&date=2026-09-29&view=rider&q=Rossi&sort=pending';
        $this->actingAs($admin)->get('/orders/'.$order->slug.'?return_to='.urlencode($context))->assertSee('href="'.htmlspecialchars($context, ENT_QUOTES).'"', false);
        $this->get($context)->assertSee('aria-pressed="true">Per Rider', false)->assertSee('value="Rossi"', false);
        $this->get('/orders/'.$order->slug.'?return_to='.urlencode('https://example.test/'))->assertDontSee('href="https://example.test/"', false)->assertSee(route('orders.incoming'));
    }

    public function test_periods_cross_year_boundaries_without_duplicate_events(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        foreach (['2026-12-28', '2026-12-31', '2027-01-01', '2027-01-03', '2027-01-04', '2027-01-31', '2027-02-01'] as $date) {
            $order = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'pickup_date' => $date]);
            foreach ([OrderStatus::Received, OrderStatus::Accepted, OrderStatus::PickedUp] as $status) {
                $order->events()->create(['user_id' => $admin->id, 'rider_id' => $admin->id, 'status' => $status]);
            }
        }
        $this->actingAs($admin)->getJson('/rider-operations/feed?period=week')->assertJsonPath('date', '2026-12-28')->assertJsonPath('to', '2027-01-03')->assertJsonPath('summary.total', 4);
        $this->getJson('/rider-operations/feed?period=month')->assertJsonPath('summary.total', 4)->assertJsonPath('to', '2027-01-31');
        $this->getJson('/rider-operations/feed?period=today')->assertJsonPath('summary.total', 1);
        $this->getJson('/rider-operations/feed?period=custom&date=2027-02-05')->assertJsonPath('summary.total', 0);
        $this->getJson('/rider-operations/feed?period=invalid')->assertUnprocessable();
        $this->getJson('/rider-operations/detail?rider='.$admin->id)->assertUnprocessable();
    }

    #[TestWith([0, 0])]
    #[TestWith([1, 1])]
    #[TestWith([10, 1])]
    #[TestWith([11, 1])]
    #[TestWith([21, 2])]
    public function test_rider_day_paginates_delivery_cards_and_keeps_full_kpis(int $count, int $pages): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'Europe/Rome'));
        $admin = $this->admin();
        $orders = Order::factory()->count($count)->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'pickup_date' => '2026-10-06', 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'store_name' => 'Negozio Rossi']);
        foreach ($orders as $order) {
            foreach ([OrderStatus::Accepted, OrderStatus::PickedUp, OrderStatus::OutForDelivery, OrderStatus::Delivered] as $status) {
                $order->events()->create(['user_id' => $admin->id, 'rider_id' => $admin->id, 'status' => $status]);
            }
        }
        $response = $this->actingAs($admin)->get('/rider-operations/'.$admin->slug.'?period=today&view=rider&zone=Caserta')->assertViewHas('counts', fn ($counts) => $counts['completed'] === $count)->assertViewHas('orders', fn ($orders) => $orders->count() === min(20, $count) && $orders->total() === $count && $orders->lastPage() === max(1, $pages))->assertDontSee('data-rider-gps', false)->assertDontSee('gps-bar', false);
        $this->assertSame(min(20, $count), substr_count($response->getContent(), 'data-rider-delivery='));
        if ($count) {
            $response->assertSee('Negozio Rossi')->assertSee('Apri ordine')->assertDontSee('Pacco ritirato');
        }
        if ($pages > 1) {
            $response->assertSee('Pagina 1 di '.$pages)->assertSee('view=rider', false);
        }
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if ($directory && $count === 21) {
            file_put_contents($directory.'/revision-rider.html', $response->getContent());
            file_put_contents($directory.'/revision-zone.html', $this->get('/rider-operations?period=today&view=rider')->getContent());
            file_put_contents($directory.'/revision-feed.json', $this->getJson('/rider-operations/feed?period=today')->getContent());
        }
    }
}
