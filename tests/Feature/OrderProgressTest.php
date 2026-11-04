<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\OrderProgress;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_actions_update_the_real_milestones_and_optional_steps_stay_unconfirmed(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'pickup_date' => now('Europe/Rome')->toDateString()]);
        $this->actingAs($admin)->get('/orders/'.$order->slug)->assertSee('data-progress-status="received" data-progress-state="current"', false)->assertDontSee('data-tracking-panel', false)->assertDontSee('transition-note-', false)->assertDontSee('data-map-points', false);
        foreach (['accepted', 'rider_arriving', 'picked_up', 'out_for_delivery', 'delivered'] as $index => $status) {
            $data = ['status' => $status, 'version' => $index + 1];
            if ($status === 'accepted') {
                $data += ['rider_id' => $admin->slug, 'price' => '5,00'];
            }
            $response = $this->patchJson('/orders/'.$order->slug, $data)->assertOk()->assertJsonPath('status', $status);
            $this->assertStringContainsString('data-progress-status="'.$status.'" data-progress-state="'.($status === 'delivered' ? 'complete' : 'current').'"', $response->json('html'));
            $this->assertStringNotContainsString('data-progress-status="pickup_scheduled"', $response->json('html'));
            $this->assertStringContainsString('data-progress-status="in_transit" data-progress-state="future"', $response->json('html'));
        }
        $this->get('/orders/'.$order->slug)->assertSee('data-progress-status="delivered" data-progress-state="complete"', false);
        $this->patchJson('/orders/'.$order->slug.'/map-points', ['version' => 6, 'kind' => 'pickup', 'latitude' => 40, 'longitude' => 14])->assertNotFound();
    }

    public function test_exception_and_price_proposal_do_not_complete_missing_milestones(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach ([OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::DeliveryAttempted, OrderStatus::DeliveryIssue, OrderStatus::Rescheduled] as $status) {
            $order = Order::factory()->create(['created_by' => $admin->id, 'status' => $status, 'price_state' => 'awaiting_customer']);
            $order->events()->create(['status' => $status, 'user_id' => $admin->id, 'public_note' => 'Messaggio operativo']);
            $progress = app(OrderProgress::class)->forOrder($order, $order->events);
            $this->assertSame($status->label(), $progress['exception']['label']);
            $this->assertSame('Messaggio operativo', $progress['exception']['note']);
            $this->assertSame('Nuova proposta', $progress['pricing']);
            $this->assertSame('future', collect($progress['steps'])->firstWhere('status', 'picked_up')['state']);
            $this->assertSame('future', collect($progress['steps'])->firstWhere('status', 'delivered')['state']);
        }
    }

    public function test_progress_reads_events_outside_the_paginated_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        for ($index = 0; $index < 35; $index++) {
            $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Accepted]);
        }
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::PickedUp]);
        $this->actingAs($admin)->get('/orders/'.$order->slug.'?events_page=1')->assertSee('data-progress-status="picked_up" data-progress-state="complete"', false);
        $this->get('/orders/'.$order->slug.'?events_page=2')->assertSee('data-progress-status="picked_up" data-progress-state="complete"', false);
    }
}
