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

    public function test_pickup_confirmation_message_and_cancellation_are_grouped_with_working_forms(): void
    {
        $rider = User::factory()->create(['name' => 'Giovanni Battista Esposito']);
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::RiderArriving]);

        $response = $this->actingAs($rider)->get(route('orders.show', $order));

        $response->assertSee('Conferma pacco ritirato')->assertSee('Giovanni Battista Esposito')->assertDontSee('Contatta il cliente');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $header = $xpath->query('//*[@id="next-action"]/header')->item(0);
        $actions = $xpath->query('.//*[contains(@class,"workflow-primary-actions")]/button | .//*[contains(@class,"workflow-primary-actions")]/a', $header);
        $this->assertSame(3, $actions->length);
        $this->assertSame('Conferma pacco ritirato', $actions->item(0)->getAttribute('aria-label'));
        $this->assertSame('primary-transition-'.$order->id, $actions->item(0)->getAttribute('form'));
        $this->assertSame(route('messages.show', $order), $actions->item(1)->getAttribute('href'));
        $this->assertSame('Annulla spedizione', $actions->item(2)->getAttribute('aria-label'));
        $this->assertSame('', trim($actions->item(2)->textContent));
        $this->assertSame(1, $xpath->query('//form[@id="primary-transition-'.$order->id.'"]//input[@name="status" and @value="picked_up"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="workflow-'.$order->id.'-cancelled"]//form/input[@name="version"]')->length);
        $this->assertSame(0, $xpath->query('//form//form')->length);
    }
}
