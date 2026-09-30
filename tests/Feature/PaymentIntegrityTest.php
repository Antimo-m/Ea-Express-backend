<?php

namespace Tests\Feature;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): Order
    {
        return Order::factory()->create([
            'customer_id' => User::factory()->state(['role' => UserRole::Customer]),
            'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'shipping_type' => 'external',
            'price_cents' => 1000, 'quoted_price_cents' => 1000, 'carrier_cost_cents' => 400,
            'pricing_version' => 1, 'price_state' => 'agreed', ...$attributes,
        ]);
    }

    public static function invalidReceipts(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'partial' => ['6'], 'overpayment' => ['10.01']];
    }

    #[DataProvider('invalidReceipts')]
    public function test_direct_receipt_rejects_any_amount_other_than_the_positive_full_balance(string $amount): void
    {
        $order = $this->order();
        $before = $order->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->postJson('/balance/'.$order->id.'/payment', [
                'action' => 'receive', 'version' => 1, 'received_amount' => $amount,
                'price_cents' => 1, 'paid_at' => now()->toIso8601String(), 'ea_amount_cents' => 99999,
            ])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    public function test_full_receipt_reverse_restore_and_new_receipt_preserve_the_net_balance(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $path = '/balance/'.$order->id.'/payment';
        $this->actingAs($admin)->postJson($path, ['action' => 'receive', 'version' => 1, 'received_amount' => '10,00'])->assertOk();
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame($admin->id, $order->fresh()->paid_by);
        $this->postJson($path, ['action' => 'reverse', 'version' => 2, 'note' => 'Rettifica'])->assertOk();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, (int) PaymentEntry::sum('amount_cents'));
        $this->postJson($path, ['action' => 'restore', 'version' => 3, 'note' => 'Nuova registrazione'])->assertOk();
        $this->assertNull($order->fresh()->paid_at);
        $this->postJson($path, ['action' => 'receive', 'version' => 4, 'received_amount' => '10'])->assertOk();
        $this->assertSame(1000, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame(1000, (int) PaymentEntry::sum('ea_amount_cents'));
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(1000, $order->fresh()->price_cents);
        $this->assertSame(400, $order->fresh()->carrier_cost_cents);
    }

    public function test_partial_payments_remain_supported_in_pending_accounts_and_only_the_full_total_marks_paid(): void
    {
        $order = $this->order();
        $account = PendingAccount::factory()->create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'amount_cents' => 1000]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $path = '/pending/'.$account->id.'/settlements';
        $first = ['version' => 1, 'amount' => '6', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson($path, $first)->assertOk();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(600, (int) PaymentEntry::sum('amount_cents'));
        $this->assertSame('partially_paid', $account->fresh()->state);
        $this->postJson($path, $first)->assertOk();
        $this->assertDatabaseCount('payment_entries', 1);
        $orderBefore = $order->fresh()->getAttributes();
        $accountBefore = $account->fresh()->getAttributes();
        $auditCount = EconomicAudit::count();
        foreach (['0', '-1', '4.01'] as $amount) {
            $this->postJson($path, ['version' => 2, 'amount' => $amount, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson('/balance/'.$order->id.'/payment', ['action' => 'receive', 'version' => 2, 'received_amount' => '4'])->assertConflict();
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($accountBefore, $account->fresh()->getAttributes());
        $this->assertDatabaseCount('economic_audits', $auditCount);
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertDatabaseCount('pending_settlements', 1);
        $this->postJson($path, ['version' => 2, 'amount' => '4', 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame(1000, (int) PaymentEntry::sum('amount_cents'));
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('paid', $account->fresh()->state);
    }

    public function test_a_zero_price_cannot_create_a_zero_value_regional_receipt(): void
    {
        $order = $this->order(['shipping_type' => 'regional', 'price_cents' => 0, 'quoted_price_cents' => 0]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->postJson('/balance/'.$order->id.'/payment', ['action' => 'receive', 'version' => 1])->assertUnprocessable();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('payment_entries', 0);
    }
}
