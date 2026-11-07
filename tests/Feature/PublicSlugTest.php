<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_is_unique_stable_and_used_by_staff_and_customer_routes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $other = Order::factory()->create();
        $slug = $order->slug;
        $this->assertTrue(Str::isUuid($slug));
        $this->assertNotSame($slug, $other->slug);
        $order->update(['notes' => 'Aggiornamento']);
        $this->assertSame($slug, $order->refresh()->slug);
        $this->assertStringEndsWith('/orders/'.$slug, route('orders.show', $order));
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk();
        $this->get('/orders/'.$order->id)->assertNotFound();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$slug)->assertOk()->assertJsonPath('data.slug', $slug)->assertJsonMissingPath('data.id');
        $this->getJson('/api/v1/customer/orders/'.$order->id)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]), 'customer')->getJson('/api/v1/customer/orders/'.$slug)->assertNotFound();
    }

    public function test_filters_labels_and_rider_assignments_pass_slugs_and_reject_numeric_ids(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($admin)->get(route('orders.in-progress', ['customer_id' => $customer->slug]))->assertOk()->assertSee($order->slug);
        $this->get(route('orders.create'))->assertOk()->assertSee('value="'.$customer->slug.'"', false);
        $this->getJson(route('orders.in-progress', ['customer_id' => $customer->id]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->get(route('labels.index', ['ids' => [$order->slug]]))->assertOk()->assertSee($order->reference);
        $this->getJson(route('labels.index', ['ids' => [$order->id]]))->assertUnprocessable();
        $this->patchJson(route('orders.rider', $order), ['rider_id' => $rider->id, 'version' => $order->version])->assertUnprocessable();
        $this->patchJson(route('orders.rider', $order), ['rider_id' => $rider->slug, 'version' => $order->version])->assertOk();
        $this->assertSame($rider->id, $order->refresh()->rider_id);
    }

    public function test_message_receipts_and_pending_details_resolve_slugs_with_customer_scoping(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $message = OrderMessage::factory()->create(['order_id' => $order->id, 'user_id' => User::factory()->create()->id]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug.'/messages')->assertOk()->assertJsonPath('data.0.slug', $message->slug);
        $this->patchJson('/api/v1/customer/orders/'.$order->slug.'/messages/read', ['state' => 'read', 'ids' => [$message->id]])->assertUnprocessable();
        $this->patchJson('/api/v1/customer/orders/'.$order->slug.'/messages/read', ['state' => 'read', 'ids' => [$message->slug]])->assertOk();
        $this->assertNotNull($message->refresh()->read_at);
        $this->getJson('/api/v1/customer/pending/'.$account->slug)->assertOk()->assertJsonPath('data.slug', $account->slug);
        $this->getJson('/api/v1/customer/pending/'.$account->id)->assertNotFound();
    }

    public function test_migration_backfills_existing_rows_without_changing_relations_or_slugs(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $migration = require database_path('migrations/2026_10_05_114858_add_public_slugs_to_workspace_tables.php');
        $migration->down();
        $migration->up();
        $order->refresh();
        $this->assertTrue(Str::isUuid($order->slug));
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame(0, DB::table('orders')->whereNull('slug')->count());
    }
}
