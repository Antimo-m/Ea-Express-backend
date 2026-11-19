<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stato del prezzo nel portale: «Da confermare» con prezzo finale 0,00 € finché il rider non accetta
 * l'ordine; «Prezzo accettato» con il valore concordato dopo l'accettazione. Il prezzo usato da
 * incassi e bilancio (price_cents, price_state) non viene toccato.
 */
class CustomerPriceStatusTest extends TestCase
{
    use RefreshDatabase;

    private function checkoutOrder(User $customer): Order
    {
        return Order::factory()->create([
            'customer_id' => $customer->id, 'created_by' => $customer->id, 'pricing_version' => 1,
            'quoted_price_cents' => 700, 'price_cents' => 700, 'price_state' => 'agreed', 'status' => OrderStatus::Received,
        ]);
    }

    public function test_price_is_to_be_confirmed_until_the_rider_accepts_the_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->checkoutOrder($customer);

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()
            ->assertJsonPath('data.price_label', 'Da confermare')->assertJsonPath('data.price_tone', 'warning')
            ->assertJsonPath('data.agreed_price_cents', null)->assertJsonPath('data.quoted_price_cents', 700);
        $this->assertSame(700, $order->fresh()->price_cents, 'Il prezzo contabile resta invariato.');
        $this->assertSame('agreed', $order->fresh()->price_state);

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug, ['status' => 'accepted', 'rider_id' => $rider->slug, 'version' => 1])->assertOk();

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)
            ->assertJsonPath('data.price_label', 'Prezzo accettato')->assertJsonPath('data.price_tone', 'green')
            ->assertJsonPath('data.agreed_price_cents', 700)->assertJsonPath('data.price_cents', 700);
    }

    public function test_adjusted_price_counts_as_agreed_only_after_customer_confirmation_and_rider_acceptance(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->checkoutOrder($customer);

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug.'/price', ['action' => 'propose', 'price' => '9.00', 'reason' => 'Pacco voluminoso', 'version' => 1])->assertOk();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)
            ->assertJsonPath('data.price_label', 'Nuova proposta')->assertJsonPath('data.agreed_price_cents', null);

        $this->patchJson('/api/v1/customer/orders/'.$order->slug.'/price', ['action' => 'accept', 'version' => 2])->assertOk();
        $this->getJson('/api/v1/customer/orders/'.$order->slug)
            ->assertJsonPath('data.price_label', 'Da confermare')->assertJsonPath('data.agreed_price_cents', null);

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug, ['status' => 'accepted', 'rider_id' => $rider->slug, 'version' => 3])->assertOk();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)
            ->assertJsonPath('data.price_label', 'Prezzo accettato')->assertJsonPath('data.agreed_price_cents', 900);
    }

    public function test_rejected_adjustment_never_becomes_an_accepted_price(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->checkoutOrder($customer);

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug.'/price', ['action' => 'propose', 'price' => '9.00', 'reason' => 'Pacco voluminoso', 'version' => 1])->assertOk();
        $this->actingAs($customer, 'customer')->patchJson('/api/v1/customer/orders/'.$order->slug.'/price', ['action' => 'reject', 'version' => 2])->assertOk();
        $this->getJson('/api/v1/customer/orders/'.$order->slug)->assertJsonPath('data.price_label', 'Proposta rifiutata')->assertJsonPath('data.price_tone', 'danger');

        $this->actingAs($admin)->patchJson('/orders/'.$order->slug, ['status' => 'accepted', 'rider_id' => $rider->slug, 'version' => 3])->assertStatus(409);
        $this->assertSame('rejected', $order->fresh()->price_state);
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertJsonPath('data.agreed_price_cents', null);
    }

    public function test_cancelled_request_never_shows_an_agreed_price(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->checkoutOrder($customer);

        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/'.$order->slug.'/cancel', ['version' => 1, 'reason' => 'Non serve più'])->assertOk();
        $this->getJson('/api/v1/customer/orders/'.$order->slug)
            ->assertJsonPath('data.price_label', 'Non concordato')->assertJsonPath('data.agreed_price_cents', null);
    }
}
