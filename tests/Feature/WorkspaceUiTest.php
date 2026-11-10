<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_details_escape_untrusted_names_addresses_and_notes(): void
    {
        $payload = '<img src=x onerror=alert(1)><script>alert(1)</script>';
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create([
            'store_name' => $payload, 'recipient_name' => $payload,
            'pickup_address' => $payload, 'delivery_address' => $payload,
            'notes' => $payload,
        ]);

        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()
            ->assertSee($payload)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unrecorded_receipts_remain_before_independently_paginated_history(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 22)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $unpaid = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now()->subDay(), 'store_name' => 'Incasso in attesa']);
        $recorded = Order::factory()->count(11)->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'store_name' => 'Incasso completato', 'price_cents' => 1000]);
        $this->actingAs($admin);
        foreach ($recorded as $receipt) {
            $this->postJson(route('payments.store', $receipt), ['action' => 'receive', 'version' => 1])->assertOk();
        }

        $page = $this->actingAs($admin)->get('/balance?ledger=payments&movements_page=2');

        $page->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $unpaid->id)
            ->assertViewHas('registerEntries', fn ($entries) => $entries->total() === 11 && $entries->currentPage() === 2 && $entries->count() === 1)
            ->assertSeeInOrder(['Incassi da registrare', 'Incasso in attesa', 'Movimenti di cassa', 'Incasso completato'])
            ->assertDontSee('id="manual-details"', false);
    }

    public function test_operational_pages_render_with_shared_filters_and_modal_forms(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 22)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Amministratore Test Secondo']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Cliente dimostrativo con un nome molto lungo']);
        ShippingRate::factory()->create(['city' => 'Località dimostrativa']);
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Accepted, 'store_name' => 'Negozio dimostrativo']);
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'store_name' => 'Incasso da registrare']);
        Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'paid_at' => now(), 'store_name' => 'Incasso registrato']);
        Expense::factory()->create(['user_id' => $admin->id, 'description' => 'Spesa dimostrativa con descrizione estesa', 'amount_cents' => 1234]);
        PendingAccount::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'subject' => 'Soggetto dimostrativo', 'description' => str_repeat('Descrizione estesa ', 12)]);
        $this->actingAs($admin);

        foreach (['incoming', 'outgoing'] as $direction) {
            $account = PendingAccount::factory()->create(['created_by' => $admin->id, 'direction' => $direction, 'subject' => $direction === 'incoming' ? 'Credito con pagamento parziale' : 'Fornitore saldato', 'amount_cents' => 20000]);
            $this->postJson(route('pending.settle', $account), ['version' => 1, 'amount' => $direction === 'incoming' ? '80' : '200', 'method' => 'cash', 'submission_key' => (string) Str::uuid()])->assertOk();
        }
        $reversed = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'store_name' => 'Incasso stornato da ripristinare', 'price_cents' => 8500]);
        $this->postJson(route('payments.store', $reversed), ['version' => 1, 'action' => 'receive', 'method' => 'cash'])->assertOk();
        $this->postJson(route('payments.store', $reversed), ['version' => 2, 'action' => 'reverse', 'note' => 'Rettifica dimostrativa'])->assertOk();
        foreach ([2, 4, 7, 9, 13, 16, 20] as $day) {
            $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered, 'created_at' => now()->startOfMonth()->addDays($day - 1), 'delivered_at' => now(), 'price_cents' => $day * 750, 'store_name' => 'Spedizione dimostrativa']);
            $this->postJson(route('payments.store', $order), ['version' => 1, 'action' => 'receive', 'method' => 'cash'])->assertOk();
            PaymentEntry::where('order_id', $order->id)->update(['created_at' => $order->created_at]);
        }

        foreach (['balance', 'pending', 'customers', 'rates', 'reports', 'orders/in-progress', 'orders/history', 'orders/incoming', 'pickups'] as $path) {
            $page = $this->get('/'.$path)->assertOk();
            if ($path === 'pickups') {
                $page->assertSee('data-pickup-calendar', false);
                $day = $this->get(route('pickups.day', ['date' => now('Europe/Rome')->addDay()->toDateString()]))
                    ->assertOk()->assertViewMissing('orders')->assertDontSee('data-operational-order=', false);
                if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                    file_put_contents($directory.'/workspace-pickup-day.html', $day->getContent());
                }
            } else {
                $page->assertSee('aria-label="Ripristina filtri"', false);
            }
            if (getenv('EA_UI_CAPTURE')) {
                file_put_contents('/private/tmp/ea-ui-final/'.str_replace('/', '-', $path).'.html', $page->getContent());
            }
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/workspace-'.str_replace('/', '-', $path).'.html', $page->getContent());
            }
        }
        foreach (['settings.index', 'profile.edit', 'users.index', 'notifications.index'] as $route) {
            $page = $this->get(route($route))->assertOk();
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/workspace-'.str_replace('.', '-', $route).'.html', $page->getContent());
            }
        }
    }
}
