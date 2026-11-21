<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_keep_counts_unread_reference_and_most_recent_first_order_across_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $orders = Order::factory()->count(14)->create();
        foreach ($orders as $index => $order) {
            $this->travelTo(now()->startOfDay()->addMinutes($index));
            foreach (range(1, 1 + $index % 3) as $notification) {
                $admin->notify(new OrderActivity($order->id, $order->reference, 'Aggiornamento '.$notification));
            }
            if ($index % 2 === 0) {
                $admin->notifications()->where('order_id', $order->id)->latest('id')->first()->markAsRead();
            }
        }
        $this->travelBack();

        $first = $this->actingAs($admin)->get(route('notifications.index'))->assertOk()->viewData('groups');
        $this->assertSame(14, $first->total());
        $this->assertSame(2, $first->lastPage());
        $this->assertSame($orders->reverse()->take(12)->pluck('reference')->all(), collect($first->items())->pluck('reference')->all());
        $latest = collect($first->items())->first();
        $this->assertSame($orders->last()->slug, $latest->order_slug);
        $this->assertSame(1 + 13 % 3, $latest->total);
        $this->assertSame(1 + 13 % 3, $latest->unread);
        $oldest = collect($this->get(route('notifications.index', ['page' => 2]))->assertOk()->viewData('groups')->items())->last();
        $this->assertSame($orders->first()->reference, $oldest->reference);
        $this->assertSame(1, $oldest->total);
        $this->assertSame(0, $oldest->unread);
    }

    public function test_groups_with_the_same_latest_instant_follow_the_order_identifier_across_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $orders = Order::factory()->count(30)->create();
        $this->travelTo(now()->startOfDay());
        foreach ($orders->shuffle() as $order) {
            $admin->notify(new OrderActivity($order->id, $order->reference, 'Stesso istante'));
        }
        $this->travelTo(now()->addMinute());
        $admin->notify(new OrderActivity($orders[3]->id, $orders[3]->reference, 'Più recente'));
        $this->travelBack();

        $expected = collect([$orders[3]->reference])->concat($orders->reject(fn (Order $order): bool => $order->is($orders[3]))->sortByDesc('id')->pluck('reference'))->all();
        $this->actingAs($admin);
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $groups = $this->get(route('notifications.index', ['page' => $page]))->assertOk()->viewData('groups');
            $this->assertSame(30, $groups->total());
            $seen = [...$seen, ...collect($groups->items())->pluck('reference')->all()];
        }
        $this->assertSame($expected, $seen);
        $this->assertSame(2, collect($this->get(route('notifications.index'))->viewData('groups')->items())->first()->total);
    }

    public function test_feeds_return_the_fifty_most_recent_visible_items_and_the_exact_unread_count(): void
    {
        $rider = User::factory()->create();
        $visible = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $hidden = Order::factory()->create(['rider_id' => User::factory()->create()->id, 'status' => OrderStatus::Accepted]);
        foreach (range(1, 60) as $minute) {
            $this->travelTo(now()->startOfDay()->addMinutes($minute));
            $rider->notify(new OrderActivity($visible->id, $visible->reference, 'Visibile '.$minute));
            $rider->notify(new OrderActivity($hidden->id, $hidden->reference, 'Nascosta '.$minute));
        }
        $this->travelBack();
        $rider->notifications()->where('order_id', $visible->id)->oldest()->limit(5)->get()->each->markAsRead();

        $feed = $this->actingAs($rider)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 55)->assertJsonCount(50, 'items');
        $expected = $rider->notifications()->where('order_id', $visible->id)->latest()->orderByDesc('id')->limit(50)->pluck('id')->all();
        $this->assertSame($expected, array_column($feed->json('items'), 'id'));
    }

    public function test_customer_portal_inbox_uses_the_same_grouping_and_feed(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $mine = Order::factory()->count(2)->create(['customer_id' => $customer->id]);
        $other = Order::factory()->create(['customer_id' => User::factory()->create(['role' => UserRole::Customer])->id]);
        foreach ([$mine[0], $mine[1], $mine[1], $other] as $minute => $order) {
            $this->travelTo(now()->startOfDay()->addMinutes($minute));
            $customer->notify(new OrderActivity($order->id, $order->reference, 'Aggiornamento'));
        }
        $this->travelBack();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/customer/notifications?grouped=1')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', $mine[1]->reference)->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.1.reference', $mine[0]->reference)->assertJsonPath('data.1.total', 1);
        $this->getJson('/api/v1/customer/notifications/feed')->assertOk()->assertJsonPath('unread', 3)->assertJsonCount(3, 'items');
    }

    public function test_grouped_notifications_carry_the_current_order_priority(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $urgent = Order::factory()->create(['customer_id' => $customer->id, 'urgency' => 'urgent', 'status' => OrderStatus::Accepted]);
        $standard = Order::factory()->create(['customer_id' => $customer->id, 'urgency' => 'standard']);
        foreach ([$standard, $urgent] as $minute => $order) {
            $this->travelTo(now()->startOfDay()->addMinutes($minute));
            $customer->notify(new OrderActivity($order->id, $order->reference, 'Aggiornamento'));
        }
        $this->travelBack();

        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/notifications?grouped=1')->assertOk()
            ->assertJsonPath('data.0.reference', $urgent->reference)->assertJsonPath('data.0.urgency', 'urgent')
            ->assertJsonPath('data.1.urgency', 'standard');
        $urgent->forceFill(['status' => OrderStatus::InTransit])->save();
        $this->getJson('/api/v1/customer/notifications?grouped=1')->assertJsonPath('data.0.urgency', 'urgent');
    }

    public function test_the_order_column_is_derived_from_the_stored_notification(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create();
        $admin->notify(new OrderActivity($order->id, $order->reference, 'Nuova richiesta'));
        DB::table('notifications')->insert(['id' => (string) str()->uuid(), 'type' => 'altro', 'notifiable_type' => User::class, 'notifiable_id' => $admin->id, 'data' => json_encode(['title' => 'Senza ordine']), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([$order->id, null], DB::table('notifications')->orderBy('created_at')->orderByRaw('order_id IS NULL')->pluck('order_id')->map(fn ($id) => $id === null ? null : (int) $id)->all());
        $this->assertSame(1, $this->actingAs($admin)->getJson(route('notifications.feed'))->json('unread'));
    }
}
