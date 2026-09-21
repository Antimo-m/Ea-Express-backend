<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['store_name' => 'Ritiro dimostrativo', 'recipient_name' => 'Destinatario test', 'recipient_phone' => '0811234567', 'pickup_address' => 'Via Test', 'pickup_city' => 'Napoli', 'pickup_street_number' => '1', 'pickup_postal_code' => '80100', 'delivery_address' => 'Via Test', 'delivery_city' => 'Caserta', 'delivery_street_number' => '2', 'delivery_postal_code' => '81100', 'pickup_date' => '2026-09-20', 'pickup_from' => '16:00', 'pickup_to' => '17:00', 'parcel_count' => 2, 'category' => 'other', 'urgency' => 'standard', 'package_type' => 'standard', 'payment_method' => 'cash', 'parcel_value' => '50'];
    }

    public function test_accounts_group_different_pickup_names_and_separate_unassigned_orders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = User::factory()->create(['role' => UserRole::Customer, 'name' => 'KLA']);
        foreach ([500, 500, 600, 700] as $i => $price) {
            Order::factory()->create(['customer_id' => $account->id, 'store_name' => 'Punto '.$i, 'price_cents' => $price, 'status' => OrderStatus::Delivered, 'parcel_count' => 3]);
        }
        $other = User::factory()->create(['role' => UserRole::Customer, 'name' => 'KLA']);
        Order::factory()->create(['customer_id' => $other->id, 'price_cents' => 900]);
        Order::factory()->count(2)->create(['customer_id' => null]);
        $this->actingAs($admin)->get('/stores')->assertOk()
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 3)
            ->assertViewHas('accountPrices', fn ($prices) => $prices->get($account->id)->count() === 3 && (int) $prices->get($account->id)->first()->shipments === 2)
            ->assertSee('23,00')->assertSee('Senza account associato');
        $this->get('/stores?q=KLA')->assertOk()->assertViewHas('stores', fn ($stores) => $stores->total() === 2);
        $this->get('/stores?q=Punto')->assertOk()->assertViewHas('stores', fn ($stores) => $stores->isEmpty());
        $this->get('/stores?unassigned=1')->assertOk()->assertViewHas('detail', fn ($detail) => $detail['total'] === 2);
        $this->get('/stores?customer_id='.$account->id.'&tariff=500')->assertOk()->assertViewHas('detailOrders', fn ($orders) => $orders->total() === 2);
    }

    public function test_rates_can_be_disabled_reenabled_and_archived_without_destroying_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rate = ShippingRate::factory()->create();
        $order = Order::factory()->create(['shipping_rate_id' => $rate->id, 'price_cents' => 500]);
        $this->actingAs($admin);
        $inactive = $this->patchJson('/rates/'.$rate->id.'/state', ['active' => false])->assertOk()->json('data.id');
        $this->getJson('/rates?state=all')->assertOk()->assertJsonPath('rates.total', 1);
        $this->patchJson('/rates/'.$rate->id.'/state', ['active' => true])->assertConflict();
        $active = $this->patchJson('/rates/'.$inactive.'/state', ['active' => true])->assertOk()->json('data.id');
        $archived = $this->deleteJson('/rates/'.$active)->assertOk()->json('data.id');
        $this->getJson('/rates?state=all')->assertJsonPath('rates.total', 0);
        $this->getJson('/rates?state=archived')->assertJsonPath('rates.total', 1)->assertJsonPath('rates.data.0.active', false);
        $this->getJson('/rates/'.$archived.'/history')->assertOk()->assertJsonCount(4, 'data');
        $this->patchJson('/rates/'.$archived.'/state', ['active' => true])->assertConflict();
        $this->assertSame(500, $order->fresh()->price_cents);
        $this->assertSame($rate->id, $order->fresh()->shipping_rate_id);
        $this->assertDatabaseHas('economic_audits', ['action' => 'rate.archived']);
        $this->actingAs(User::factory()->create())->deleteJson('/rates/'.$archived)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->getJson('/api/v1/customer/rates?state=archived')->assertJsonPath('rates.total', 0);
    }

    public function test_pickup_time_is_checked_at_review_and_final_confirmation(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 15:00:00', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->getJson('/api/v1/customer/booking-rules')->assertOk()->assertJsonPath('timezone', 'Europe/Rome')->assertJsonPath('minimum_pickup', '2026-09-20T15:00:00+02:00');
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'pickup_from' => '12:00'])->assertUnprocessable()->assertJsonValidationErrors('pickup_from');
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'pickup_date' => '2026-09-21', 'pickup_from' => '12:00'])->assertOk();
        $data = $this->checkoutData([...$this->payload(), 'pickup_from' => '15:01']);
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/customer/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('pickup_from');
        $this->assertDatabaseCount('orders', 0);
        $valid = $this->checkoutData($this->payload());
        $this->postJson('/api/v1/customer/orders', $valid)->assertCreated()->assertJsonPath('data.total_cents', 5500);
    }

    public function test_value_is_required_with_exact_money_and_zero_is_explicitly_allowed(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 15:00:00', 'Europe/Rome'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        foreach ([null, '', '-1', 'abc', '1.001', '1,2.3'] as $value) {
            $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'parcel_value' => $value])->assertUnprocessable()->assertJsonValidationErrors('parcel_value');
        }
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'parcel_value' => '0'])->assertOk()->assertJsonPath('parcel_value_cents', 0);
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'parcel_value' => '12,34'])->assertOk()->assertJsonPath('total_cents', 1734);
        $this->actingAs(User::factory()->create(), 'web')->postJson('/orders', [...$this->payload(), 'parcel_value' => ''])->assertUnprocessable()->assertJsonValidationErrors('parcel_value');
    }

    public function test_unchanged_historical_schedule_can_be_edited_but_cannot_be_rescheduled_into_past(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 15:00:00', 'Europe/Rome'));
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create([...collect($this->payload())->except(['parcel_value', 'payment_method'])->all(), 'customer_id' => $customer->id, 'price_cents' => 500]);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/orders/'.$order->id.'/checkout', [...$this->payload(), 'version' => $order->version])->assertOk();
        $this->postJson('/api/v1/customer/orders/'.$order->id.'/checkout', [...$this->payload(), 'pickup_date' => '2026-09-21', 'pickup_from' => '12:00', 'version' => $order->version])->assertUnprocessable()->assertJsonValidationErrors('pickup_from');
    }

    public function test_account_summary_queries_do_not_grow_per_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        Order::factory()->create(['customer_id' => $customer->id, 'price_cents' => 500]);
        $this->actingAs($admin);
        DB::enableQueryLog();
        $this->get('/stores')->assertOk();
        $initial = count(DB::getQueryLog());
        foreach (range(1, 8) as $index) {
            Order::factory()->create(['customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'price_cents' => 500]);
        }
        DB::flushQueryLog();
        $this->get('/stores')->assertOk();
        $this->assertLessThanOrEqual($initial, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_booking_rejects_nonexistent_dst_time_and_yesterdays_checkout(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer');
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta']);
        $this->travelTo(Carbon::parse('2026-03-28 12:00:00', 'Europe/Rome'));
        $this->postJson('/api/v1/customer/orders/checkout', [...$this->payload(), 'pickup_date' => '2026-03-29', 'pickup_from' => '02:30', 'pickup_to' => '04:00'])->assertUnprocessable()->assertJsonValidationErrors('pickup_from');
        $this->travelTo(Carbon::parse('2026-09-20 23:50:00', 'Europe/Rome'));
        $data = $this->checkoutData([...$this->payload(), 'pickup_from' => '23:55', 'pickup_to' => '23:59']);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/customer/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('pickup_date');
        $this->assertDatabaseCount('orders', 0);
    }
}
