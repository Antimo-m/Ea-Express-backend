<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeliveryIssueCustomerMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_suspension_reason_reaches_the_customer_portal_tracking_and_notification(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'tracking_token' => str_repeat('a', 64), 'tracking_started_at' => now()]);
        $reason = 'Ordine sospeso perché ho bucato la ruota, riprendo appena possibile.';

        $this->actingAs($rider)->get('/orders/'.$order->slug)->assertOk()->assertSee('Motivo della sospensione')->assertSee('Il cliente vedrà questo messaggio');
        $this->patch('/orders/'.$order->slug, ['version' => $order->version, 'status' => 'delivery_issue', 'note' => $reason])->assertSessionHasNoErrors();

        $event = $order->events()->latest('id')->first();
        $this->assertSame(OrderStatus::DeliveryIssue, $event->status);
        $this->assertSame($reason, $event->public_note);
        $this->get('/orders/'.$order->slug)->assertOk()->assertSee('Messaggio al cliente: '.$reason, false)->assertDontSee('Nota interna: '.$reason, false);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()
            ->assertJsonPath('data.progress.exception.note', $reason)
            ->assertJsonPath('data.progress.state', 'paused');
        $this->get('/track/'.$order->fresh()->tracking_token)->assertOk()->assertSee($reason, false);
        Notification::assertSentTo($customer, OrderActivity::class, fn (OrderActivity $notification): bool => str_contains($notification->title, 'ho bucato la ruota'));
    }

    public function test_other_internal_notes_stay_private(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery]);

        $this->actingAs($rider)->patch('/orders/'.$order->slug, ['version' => $order->version, 'status' => 'delivery_attempted', 'note' => 'Citofono rotto, nota solo interna'])->assertSessionHasNoErrors();

        $this->assertNull($order->events()->latest('id')->first()->public_note);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()->assertDontSee('Citofono rotto');
    }
}
