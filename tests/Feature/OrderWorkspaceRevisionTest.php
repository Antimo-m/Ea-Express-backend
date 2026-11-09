<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderWorkspaceRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_previews_use_native_slug_links_and_four_semantic_icon_groups(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->count(4)->create(['store_name' => 'Macelleria Antimo e distribuzione alimentare del territorio', 'recipient_name' => 'Giuseppe Marconi destinatario dimostrativo']);
        Order::factory()->count(4)->create(['status' => OrderStatus::Accepted, 'rider_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertViewHas('requests', fn ($orders) => $orders->count() === 3)
            ->assertViewHas('shipments', fn ($orders) => $orders->count() === 3)
            ->assertDontSee('tabella scorribile')->assertDontSee('Stato / Rider')->assertDontSee('01 / Coordinamento')->assertDontSee('02 / Attività');
        $xpath = $this->document($response);
        $this->assertSame(4, $xpath->query('//*[contains(@class,"dashboard-section-icon")]')->length);
        $links = $xpath->query('//a[contains(@class,"dashboard-order-preview")]');
        $this->assertSame(6, $links->length);
        foreach ($links as $link) {
            $this->assertMatchesRegularExpression('#/orders/[0-9a-f-]{36}$#', $link->getAttribute('href'));
            $this->assertSame(0, $xpath->query('.//a | .//button', $link)->length);
        }
        $this->export('workspace-dashboard.html', $response);
    }

    public function test_incoming_card_exposes_days_customer_recipient_complete_route_and_reference(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Antimo']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => 'Antimo', 'recipient_name' => 'Giuseppe Marconi', 'pickup_city' => 'Giugliano', 'delivery_city' => 'Caiazzo', 'created_at' => now()->subDays(7)]);

        $response = $this->actingAs($admin)->get(route('orders.incoming'));

        $response->assertSee('Antimo')->assertSee('Giuseppe Marconi')->assertSee('Giugliano')->assertSee('Caiazzo')->assertSee('7 giorni')->assertSee($order->reference);
        $xpath = $this->document($response);
        $this->assertSame(2, $xpath->query('//*[contains(@class,"operational-route")]//*[contains(@class,"shipment-route-stop")]/strong')->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class,"operational-card-heading")]/time')->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class,"operational-card-heading")]//*[contains(@class,"status-badge")]')->length);
        $this->export('workspace-incoming.html', $response);
    }

    public function test_active_cards_use_real_urgency_risk_and_assignment_age_and_have_one_message_action(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $urgent = Order::factory()->create(['status' => OrderStatus::Accepted, 'rider_id' => $admin->id, 'urgency' => 'urgent', 'recipient_name' => 'Giuseppe Marconi', 'recipient_phone' => '+393331000001', 'delivery_address' => 'Via della Prova 1', 'parcel_count' => 4, 'created_at' => now()->subDays(10), 'assigned_at' => now()->subHours(3)]);
        $standard = Order::factory()->create(['status' => OrderStatus::DeliveryIssue, 'rider_id' => $admin->id, 'urgency' => 'standard', 'recipient_name' => 'Destinatario senza precedenti', 'recipient_phone' => '+393331000002', 'delivery_address' => 'Via del Secondo Collaudo 2', 'assigned_at' => now()->subHours(49)]);
        app(RecipientRisk::class)->record($urgent, $admin);

        $response = $this->actingAs($admin)->get(route('orders.in-progress'));

        $response->assertSee('3 ore')->assertSee('2 giorni')->assertSee('4 colli')->assertSee('Nome destinatario')->assertSee($urgent->reference)->assertSee($standard->reference);
        $xpath = $this->document($response);
        $urgentCard = $xpath->query('//*[@data-operational-order="'.$urgent->slug.'"]')->item(0);
        $standardCard = $xpath->query('//*[@data-operational-order="'.$standard->slug.'"]')->item(0);
        $this->assertStringContainsString('operational-priority-urgent', $urgentCard->getAttribute('class'));
        $this->assertStringContainsString('operational-priority-standard', $standardCard->getAttribute('class'));
        $this->assertSame(1, $xpath->query('.//*[contains(@class,"operational-identity")]//*[@aria-label="Destinatario non affidabile: precedenti registrati"]', $urgentCard)->length);
        $this->assertSame(1, $xpath->query('.//*[contains(@class,"operational-identity")]//*[@aria-label="Destinatario affidabile: nessun precedente registrato"]', $standardCard)->length);
        $this->assertSame(1, $xpath->query('.//a[contains(@href,"/messages/")]', $urgentCard)->length);
        $this->assertSame(0, $xpath->query('.//*[contains(@class,"operational-preview")]//a[contains(@href,"/messages/")]', $urgentCard)->length);
        $this->export('workspace-progress.html', $response);
    }

    public function test_unidentifiable_legacy_recipients_do_not_receive_a_safe_indicator(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $unknown = Order::factory()->create(['status' => OrderStatus::Accepted, 'recipient_name' => 'Destinatario storico senza identità verificabile', 'recipient_phone' => '', 'delivery_address' => '', 'delivery_postal_code' => null]);
        Order::factory()->create(['status' => OrderStatus::Accepted]);

        $response = $this->actingAs($admin)->get(route('orders.in-progress'));

        $xpath = $this->document($response);
        $card = $xpath->query('//*[@data-operational-order="'.$unknown->slug.'"]')->item(0);
        $this->assertNotNull($card);
        $this->assertSame(0, $xpath->query('.//*[contains(@class,"recipient-dot")]', $card)->length);
    }

    public function test_history_loads_one_day_at_a_time_and_paginates_forty_orders_without_duplicates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->count(40)->create(['status' => OrderStatus::Delivered, 'store_name' => 'Archivio del 6 novembre', 'created_at' => Carbon::parse('2026-11-06 08:00', 'Europe/Rome')]);
        Order::factory()->count(12)->create(['status' => OrderStatus::Delivered, 'store_name' => 'Archivio del 5 novembre', 'created_at' => Carbon::parse('2026-11-05 08:00', 'Europe/Rome')]);
        Order::factory()->count(8)->create(['status' => OrderStatus::Cancelled, 'store_name' => 'Archivio del 4 novembre', 'created_at' => Carbon::parse('2026-11-04 08:00', 'Europe/Rome')]);

        $first = $this->actingAs($admin)->get(route('orders.history'));

        $first->assertViewHas('archiveTotal', 60)->assertViewHas('selectedDate', '2026-11-06')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 40 && $orders->count() === 20)
            ->assertViewHas('archiveDays', fn ($days) => $days->pluck('total')->all() === [40, 12, 8])
            ->assertDontSee('Archivio del 5 novembre')->assertDontSee('Archivio del 4 novembre');
        $second = $this->get(route('orders.history', ['day' => '2026-11-06', 'day_page' => 2]));
        $second->assertViewHas('orders', fn ($orders) => $orders->count() === 20 && $orders->currentPage() === 2);
        $this->assertSame([], array_intersect($first->viewData('orders')->modelKeys(), $second->viewData('orders')->modelKeys()));
        $previous = $this->get(route('orders.history', ['day' => '2026-11-05']));
        $previous->assertSee('Archivio del 5 novembre')->assertDontSee('Archivio del 6 novembre')
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 12 && $orders->total() === 12);
        $fragment = $this->withHeader('X-Order-History', 'day')->get(route('orders.history', ['day' => '2026-11-05']));
        $fragment->assertViewIs('orders.history-day')->assertDontSee('<html', false)->assertSee('Archivio del 5 novembre');
        $this->export('workspace-history.html', $first);
        $this->export('workspace-history-page2.html', $second);
        $this->export('workspace-history-previous.html', $previous);
        $this->export('workspace-history-previous-fragment.html', $fragment);
        $this->export('workspace-history-page2-fragment.html', $this->withHeader('X-Order-History', 'day')->get(route('orders.history', ['day' => '2026-11-06', 'day_page' => 2]))->assertOk());
        $filters = ['q' => 'Archivio', 'status' => 'delivered'];
        $this->export('workspace-history-filtered.html', $this->withHeader('X-Order-History', '')->get(route('orders.history', $filters))->assertOk());
        $this->export('workspace-history-filtered-previous.html', $this->withHeader('X-Order-History', 'day')->get(route('orders.history', [...$filters, 'day' => '2026-11-05']))->assertOk());
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/workspace-history-ids.json', json_encode(['first' => $first->viewData('orders')->pluck('slug')->all(), 'second' => $second->viewData('orders')->pluck('slug')->all()], JSON_THROW_ON_ERROR));
        }
    }

    public function test_history_preserves_filters_slug_resolution_and_rider_ownership_for_day_fragments(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'Europe/Rome'));
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $visible = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'recipient_name' => 'Destinatario filtrato', 'urgency' => 'urgent', 'created_at' => now()->subDay()]);
        $hidden = Order::factory()->create(['status' => OrderStatus::Delivered, 'recipient_name' => 'Destinatario di altro Rider', 'created_at' => now()->subDays(2)]);
        $filters = ['customer_id' => $customer->slug, 'status' => 'delivered', 'q' => 'filtrato', 'urgency' => 'urgent', 'day' => '2026-11-05'];

        $response = $this->actingAs($rider)->withHeader('X-Order-History', 'day')->get(route('orders.history', $filters));

        $response->assertViewHas('orders', fn ($orders) => $orders->pluck('slug')->all() === [$visible->slug])->assertSee('Destinatario filtrato')->assertDontSee($hidden->recipient_name);
        $this->getJson(route('orders.history', ['customer_id' => $customer->id]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->get(route('orders.history', ['day' => '2026-11-04']))->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
    }

    public function test_history_groups_by_rome_midnight_and_navigates_day_pages_directly(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['status' => OrderStatus::Delivered, 'created_at' => Carbon::parse('2026-10-25 22:59:00', 'UTC')]);
        Order::factory()->create(['status' => OrderStatus::Delivered, 'created_at' => Carbon::parse('2026-10-25 23:00:00', 'UTC')]);
        foreach (range(1, 15) as $offset) {
            Order::factory()->create(['status' => OrderStatus::Delivered, 'created_at' => Carbon::parse('2026-10-24 12:00', 'Europe/Rome')->subDays($offset)]);
        }

        $response = $this->actingAs($admin)->get(route('orders.history', ['day' => '2026-10-26']));

        $response->assertViewHas('orders', fn ($orders) => $orders->count() === 1)
            ->assertViewHas('archiveDays', fn ($days) => $days->first()['date'] === '2026-10-26' && $days->first()['total'] === 1);
        $this->get(route('orders.history', ['day' => '2026-10-09']))->assertViewHas('archiveDays', fn ($days) => $days->currentPage() === 2)->assertViewHas('orders', fn ($orders) => $orders->count() === 1);
        $this->get(route('orders.history', ['day' => '2026-11-01']))->assertViewHas('selectedDate', '2026-11-01')->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->getJson(route('orders.history', ['day' => 'not-a-date']))->assertUnprocessable()->assertJsonValidationErrors('day');
    }

    private function document(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());

        return new \DOMXPath($document);
    }

    private function export(string $name, TestResponse $response): void
    {
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/'.$name, $response->getContent());
        }
    }
}
