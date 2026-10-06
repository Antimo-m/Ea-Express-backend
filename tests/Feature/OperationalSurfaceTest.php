<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_destinations_return_not_found_and_shared_tracking_survives(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::OutForDelivery]);
        $this->actingAs($admin)->get('/tracking')->assertNotFound();
        $this->get('/stores')->assertNotFound();
        $this->get('/dashboard')->assertOk()->assertSee('data-tracking-panel', false)->assertDontSee('Mappa live')->assertDontSee('Statistiche clienti');
        $this->get('/rider-operations')->assertOk()->assertDontSee('data-operational-map', false);
        $this->getJson('/tracking/positions')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/orders/'.$order->slug.'/location')->assertOk()->assertJsonPath('order_slug', $order->slug);
    }

    public function test_customer_profile_combines_scoped_statistics_receipts_tariffs_and_all_order_states(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 5)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $completed = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'price_cents' => 500]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Received, 'price_cents' => 700]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Cancelled, 'price_cents' => 900]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => now()->subMonth(), 'price_cents' => 500]);
        Order::factory()->create(['price_cents' => 10000]);
        $payment = new PaymentEntry;
        $payment->forceFill(['order_id' => $completed->id, 'user_id' => $admin->id, 'amount_cents' => 500, 'ea_amount_cents' => 350, 'note' => 'Incasso quota EA'])->save();
        PendingAccount::factory()->create(['customer_id' => $customer->id, 'direction' => 'incoming', 'amount_cents' => 900, 'settled_cents' => 200, 'state' => 'partially_paid']);

        $this->actingAs($admin)->get('/customers/'.$customer->slug)->assertOk()
            ->assertViewHas('lifetime', fn ($data) => $data['total'] === 4)
            ->assertViewHas('summary', fn ($data) => $data['total'] === 3 && $data['delivered'] === 1 && $data['cancelled'] === 1 && $data['shipping_spend_cents'] === 1200 && $data['prices']->count() === 2)
            ->assertViewHas('averageCents', 600)->assertViewHas('receivedCents', 350)->assertViewHas('pendingCents', 700)
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 3)
            ->assertSee('/customers/'.$customer->slug.'?period=year', false);
        $this->get('/customers/'.$customer->slug.'?tariff=500')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
        $this->get('/customers/'.$customer->id)->assertNotFound();
        $this->get('/customers/'.$admin->slug)->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/customers/'.$customer->slug)->assertForbidden();
    }

    public function test_customer_directory_includes_accounts_without_orders_and_is_paginated(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Centro Distribuzione Elettrodomestici Napoli Nord']);
        $this->actingAs($admin)->get('/customers?q=Distribuzione')->assertOk()->assertViewHas('customers', fn ($customers) => $customers->total() === 1 && $customers->first()->orders_count === 0)->assertSee('/customers/'.$customer->slug, false);
        User::factory()->count(21)->create(['role' => UserRole::Customer]);
        $this->get('/customers')->assertOk()->assertViewHas('customers', fn ($customers) => $customers->count() === 20 && $customers->total() === 22);
    }

    public function test_operational_lists_keep_twenty_rows_and_bounded_preview_queries(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        foreach ([0, 1, 5, 20, 100] as $volume) {
            $existing = Order::where('status', OrderStatus::Received)->count();
            Order::factory()->count($volume - $existing)->create(['created_by' => $admin->id, 'status' => OrderStatus::Received, 'store_name' => 'Centro Distribuzione Elettrodomestici Napoli Nord', 'recipient_name' => 'Pierluigi Francesco Di Stefano', 'delivery_city' => 'Giugliano in Campania']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get('/orders/incoming')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === $volume && $orders->count() === min(20, $volume));
            $this->assertLessThan(20, count(DB::getQueryLog()));
            DB::disableQueryLog();
            $this->assertSame(min(20, $volume), substr_count($response->getContent(), 'data-operational-order='));
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/incoming-'.$volume.'.html', $response->getContent());
            }
        }
        $this->get('/orders/incoming?page=5')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 20);
        $order = Order::first();
        $order->forceFill(['status' => OrderStatus::PickedUp, 'rider_id' => $admin->id])->save();
        $order->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $admin->id]);
        $this->get('/orders/in-progress')->assertOk()->assertSee('order-progress', false)->assertSee('Pacco ritirato')->assertSee('data-order-preview-toggle', false)->assertSee('/orders/'.$order->slug, false);
    }
}
