<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderActionLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_pickup_confirmation_is_primary_with_separate_cancellation_and_messaging(): void
    {
        $rider = User::factory()->create(['name' => 'Amministratore Test']);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::RiderArriving]);

        $response = $this->actingAs($rider)->get(route('orders.show', $order));

        $response->assertSee('Conferma pacco ritirato')->assertSee('Amministratore Test')->assertDontSee('Contatta il cliente');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $header = $xpath->query('//*[@id="next-action"]/header')->item(0);
        $actions = $xpath->query('.//*[contains(@class,"workflow-primary-actions")]/button | .//*[contains(@class,"workflow-primary-actions")]/a', $header);
        $this->assertSame(1, $actions->length);
        $this->assertSame('Conferma pacco ritirato', $actions->item(0)->getAttribute('aria-label'));
        $this->assertSame('primary-transition-'.$order->slug, $actions->item(0)->getAttribute('form'));
        $this->assertSame(0, $xpath->query('//*[@id="next-action"]//a[contains(@href,"/messages/")]')->length);
        $this->assertSame(1, $xpath->query('//header[contains(@class,"order-detail-heading")]//a[contains(@href,"/messages/")]')->length);
        $cancel = $xpath->query('//*[@id="next-action"]//footer//button[@aria-label="Annulla spedizione"]')->item(0);
        $this->assertNotNull($cancel);
        $this->assertSame('workflow-'.$order->slug.'-cancelled', $cancel->getAttribute('data-dialog-open'));
        $this->assertSame('', trim($cancel->textContent));
        $this->assertSame(1, $xpath->query('//form[@id="primary-transition-'.$order->slug.'"]//input[@name="status" and @value="picked_up"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="workflow-'.$order->slug.'-cancelled"]//form/input[@name="version"]')->length);
        $this->assertSame(0, $xpath->query('//form//form')->length);
    }

    public function test_removed_pickup_step_is_rejected_without_changing_the_order(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);

        $this->actingAs($rider)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Parti per il ritiro')->assertDontSee('Programma il ritiro')
            ->assertDontSee('pickup-schedule-', false);
        $this->patchJson(route('orders.update', $order), ['status' => 'pickup_scheduled', 'version' => 1])->assertUnprocessable();
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->version);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_legacy_pickup_step_remains_readable_and_can_advance(): void
    {
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::PickupScheduled]);
        $order->events()->create(['status' => OrderStatus::PickupScheduled, 'user_id' => $rider->id]);

        $this->actingAs($rider)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Ritiro programmato')->assertSee('Parti per il ritiro');
        $this->patchJson(route('orders.update', $order), ['status' => 'rider_arriving', 'version' => 1])
            ->assertOk()->assertJsonPath('status', 'rider_arriving');
        $this->assertSame(OrderStatus::RiderArriving, $order->fresh()->status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'status' => 'pickup_scheduled']);
    }

    public function test_history_keeps_rider_scoping_search_and_pagination(): void
    {
        $this->freezeTime();
        $rider = User::factory()->create();
        Order::factory()->count(22)->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'store_name' => 'Archivio dimostrativo']);
        $hidden = Order::factory()->create(['status' => OrderStatus::Delivered, 'store_name' => 'Cliente di altro Rider']);

        $page = $this->actingAs($rider)->get(route('orders.history', ['q' => 'Archivio', 'status' => 'delivered']));
        $page->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 22 && $orders->count() === 20)
            ->assertSee('archive-day-heading', false)->assertSee('data-history-print-start', false)->assertDontSee($hidden->reference);
        $this->get(route('orders.history', ['q' => 'Archivio', 'status' => 'delivered', 'day_page' => 2]))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 22 && $orders->count() === 2);
    }
}
