<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentationRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_identity_prefers_store_then_account_and_keeps_reference_search(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Mario Rossi']);
        $shop = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => 'Fashion Love', 'recipient_name' => 'Destinatario distinto']);
        $fallback = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => '   ', 'recipient_name' => 'Altro destinatario']);
        $this->actingAs($customer, 'customer');

        $this->getJson('/api/v1/customer/orders/'.$shop->slug)->assertOk()->assertJsonPath('data.display_name', 'Fashion Love')->assertJsonPath('data.reference', $shop->reference);
        $this->getJson('/api/v1/customer/orders/'.$fallback->slug)->assertOk()->assertJsonPath('data.display_name', 'Mario Rossi');
        $this->getJson('/api/v1/customer/orders?q='.urlencode($shop->reference))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.display_name', 'Fashion Love');
        $this->getJson('/api/v1/customer/orders?q=Fashion')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->getJson('/api/v1/customer/orders/'.$shop->slug)->assertNotFound();
    }

    public function test_staff_order_without_store_or_customer_displays_creator(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Creatore account']);
        $order = Order::factory()->create(['store_name' => '', 'customer_id' => null, 'created_by' => $admin->id, 'recipient_name' => 'Non è il mittente']);

        $this->actingAs($admin)->get('/orders/'.$order->slug)->assertOk()->assertSee('<h1>Creatore account</h1>', false)->assertSee($order->reference);
        $this->get('/orders?q='.urlencode($order->reference))->assertRedirect();
    }

    public function test_statistics_show_real_order_summary_trend_and_previous_period(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 9, 17)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Account prova']);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-10 10:00:00', 'status' => OrderStatus::Delivered, 'price_cents' => 700]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-11 10:00:00', 'status' => OrderStatus::Accepted, 'shipping_type' => 'external']);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-11 11:00:00', 'status' => OrderStatus::Cancelled]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-09-08 10:00:00']);

        $this->actingAs($admin)->get('/customers/'.$customer->slug.'?period=custom&from=2026-09-10&to=2026-09-11')->assertOk()
            ->assertSee('<h1>Account prova</h1>', false)->assertDontSee('Distribuzione spese')
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 3 && $summary['delivered'] === 1 && $summary['in_progress'] === 1 && $summary['cancelled'] === 1 && $summary['external_count'] === 1)
            ->assertViewHas('comparison', fn ($summary) => $summary['total'] === 1)
            ->assertViewHas('trend', fn ($points) => array_column($points, 'orders') === [1, 2]);
    }

    public function test_message_cards_show_store_latest_message_and_real_unread_count(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Nome account diverso']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => 'Fashion Love']);
        OrderMessage::factory()->create(['order_id' => $order->id, 'body' => 'Messaggio già letto', 'read_at' => now()->subMinute()]);
        OrderMessage::factory()->create(['order_id' => $order->id, 'body' => 'Il pacco è pronto per il ritiro', 'created_at' => now()]);
        OrderMessage::factory()->create(['order_id' => $order->id, 'body' => 'Messaggio storico importato', 'created_at' => now()->subDay(), 'read_at' => now()]);

        $this->actingAs($admin)->get('/messages')->assertOk()->assertSee('Fashion Love')->assertSee('Il pacco è pronto per il ritiro')->assertSee('1 nuovo')->assertSee($order->reference)
            ->assertViewHas('orders', fn ($orders) => $orders->first()->unread_count === 1 && $orders->first()->relationLoaded('latestMessage'));
        $admin->notify(new OrderActivity($order->id, $order->reference, 'Nuovo messaggio', true, 'Nome account diverso'));
        $this->get('/notifications')->assertOk()->assertSee('Fashion Love')->assertDontSee('Nome account diverso');
    }

    public function test_statistics_detail_exposes_display_name_without_account_relationships(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Mittente account']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => '', 'price_cents' => 700]);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/statistics?tariff=700')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonMissingPath('detail')->assertJsonMissingPath('prices');
    }

    public function test_staff_form_has_five_cards_send_icon_and_shared_modal_controls(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/orders/create')->assertOk()->assertSee('staff-booking-form')->assertSee('booking-step-number')->assertSee('Tariffa')->assertSee('bi-send')->assertSee('modal-back');
        if ($directory = getenv('EA_UI_CAPTURE_DIR')) {
            $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Account cliente']);
            $order = Order::factory()->create(['customer_id' => $customer->id, 'store_name' => 'Fashion Love', 'price_cents' => 700]);
            OrderMessage::factory()->create(['order_id' => $order->id, 'body' => 'Il pacco è pronto per il ritiro']);
            foreach (['create' => '/orders/create', 'messages' => '/messages', 'customers' => '/customers', 'detail' => '/orders/'.$order->slug, 'rates' => '/rates'] as $name => $path) {
                file_put_contents($directory.'/'.$name.'.html', $this->get($path)->assertOk()->getContent());
            }
            $this->actingAs($customer, 'customer');
            file_put_contents($directory.'/auth.json', $this->getJson('/api/v1/customer/auth/me')->assertOk()->getContent());
            file_put_contents($directory.'/order.json', $this->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()->getContent());
        }
    }
}
