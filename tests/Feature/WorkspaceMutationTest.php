<?php

namespace Tests\Feature;

use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forty_consecutive_receipts_keep_filters_totals_and_cash_movements_consistent(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 3)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Batch test']);
        Order::factory()->count(43)->create([
            'created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $admin->id,
            'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'store_name' => 'Batch test',
            'price_cents' => 100, 'shipping_type' => 'regional', 'price_state' => 'agreed',
        ]);
        $movement = FinancialMovement::factory()->create([
            'user_id' => $admin->id, 'customer_id' => $customer->id, 'kind' => 'cash',
            'amount_cents' => 3000, 'occurred_on' => '2026-11-03', 'description' => 'Batch test apporto contanti',
        ]);
        $filters = ['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-15', 'customer_ids' => [$customer->slug], 'q' => 'Batch test', 'receipt_sort' => 'oldest', 'ledger' => 'payments'];
        $initialUrl = '/balance?'.http_build_query([...$filters, 'orders_page' => 2]);
        $directory = getenv('EA_UI_FIXTURE_DIR');
        $page = 2;
        $steps = [];
        $firstReceipt = null;
        $this->actingAs($admin);

        for ($step = 0; $step <= 40; $step++) {
            $url = '/balance?'.http_build_query([...$filters, 'orders_page' => $page]);
            $response = $this->get($url)->assertOk()
                ->assertViewHas('cashMovementBalance', 3000)->assertViewHas('grossIncome', $step * 100)
                ->assertViewHas('finalNet', $step * 100)->assertViewHas('deliveredCount', 43)
                ->assertViewHas('outstanding', 4300 - $step * 100)
                ->assertViewHas('selectedCustomerSlugs', [$customer->slug]);
            $orders = $response->viewData('orders');
            $this->assertSame(43 - $step, $orders->total());
            $order = $orders->first();
            $this->assertNotNull($order);
            $steps[] = [
                'step' => $step, 'page' => $page, 'remaining' => $orders->total(),
                'order_slug' => $order->slug, 'order_version' => $order->version,
                'order_reference' => $order->reference, 'payment_path' => '/balance/'.$order->slug.'/payment',
                'gross_income_cents' => $step * 100, 'cash_movement_cents' => 3000,
            ];
            if ($directory) {
                foreach ([1, 2] as $fixturePage) {
                    $snapshot = $fixturePage === $page ? $response : $this->get('/balance?'.http_build_query([...$filters, 'orders_page' => $fixturePage]))->assertOk();
                    file_put_contents($directory.'/batch-'.$step.'-page'.$fixturePage.'.html', $snapshot->getContent());
                }
            }
            if ($step === 40) {
                break;
            }
            $firstReceipt ??= $order;
            $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => $order->version])->assertOk();
            $page = $orders->total() - 1 > $orders->perPage() ? 2 : 1;
        }

        $this->assertDatabaseCount('orders', 43);
        $this->assertDatabaseCount('payment_entries', 40);
        $this->assertDatabaseCount('financial_movements', 1);
        $this->assertSame(4000, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(40, PaymentEntry::distinct()->count('order_id'));
        $this->assertSame(40, Order::whereNotNull('paid_at')->count());
        $this->assertSame(3000, $movement->fresh()->amount_cents);
        $this->postJson(route('payments.store', $firstReceipt), ['action' => 'receive', 'version' => $firstReceipt->version])->assertUnprocessable();
        $this->assertDatabaseCount('payment_entries', 40);

        if ($directory) {
            file_put_contents($directory.'/batch.json', json_encode([
                'initial_url' => $initialUrl, 'filters' => $filters, 'customer_slug' => $customer->slug,
                'order_count' => 43, 'receipt_count' => 40, 'receipt_amount_cents' => 100,
                'cash_movement_cents' => 3000, 'steps' => $steps,
            ], JSON_THROW_ON_ERROR));
        }
    }

    public function test_settings_form_updates_preferences_through_its_native_patch_endpoint(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'notify_orders' => true, 'notify_messages' => true]);
        $this->actingAs($admin);
        $before = $this->get(route('settings.index'))->assertOk();

        $this->from(route('settings.index'))->patch(route('settings.update'), ['notify_orders' => '0', 'notify_messages' => '1'])
            ->assertRedirect(route('settings.index'))->assertSessionHas('status', 'Preferenze salvate.');

        $admin->refresh();
        $this->assertFalse($admin->notify_orders);
        $this->assertTrue($admin->notify_messages);
        $after = $this->get(route('settings.index'))->assertOk()->assertSee('Preferenze salvate.');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/settings-before.html', $before->getContent());
            file_put_contents($directory.'/settings-after.html', $after->getContent());
        }
    }
}
