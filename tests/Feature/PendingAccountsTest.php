<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $direction = 'incoming'): array
    {
        return ['subject' => 'Fashion Test', 'direction' => $direction, 'description' => 'Servizio', 'amount' => '10', 'occurred_on' => now()->toDateString()];
    }

    public function test_partial_order_settlements_reconcile_cash_and_residual_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 1000]);
        $this->actingAs($admin)->postJson('/pending', [...$this->payload(), 'order_id' => $order->id])->assertOk();
        $account = PendingAccount::sole();
        $payment = ['amount' => '4', 'method' => 'cash', 'note' => 'Acconto', 'submission_key' => (string) Str::uuid(), 'version' => 1];
        $this->postJson('/pending/'.$account->id.'/settlements', $payment)->assertOk();
        $this->postJson('/pending/'.$account->id.'/settlements', $payment)->assertOk();
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertSame('partially_paid', $account->fresh()->state);
        $this->assertNull($order->fresh()->paid_at);
        $this->get('/balance')->assertOk()->assertViewHas('cash', 400)->assertViewHas('outstanding', 600);
        $this->postJson('/pending/'.$account->id.'/settlements', [...$payment, 'amount' => '5'])->assertConflict();
        $this->postJson('/balance/'.$order->id.'/payment', ['action' => 'receive', 'method' => 'cash', 'note' => 'Doppio', 'version' => 2])->assertConflict();
        $this->postJson('/pending/'.$account->id.'/settlements', [...$payment, 'submission_key' => (string) Str::uuid(), 'version' => 2, 'amount' => '7'])->assertUnprocessable();
        $this->postJson('/pending/'.$account->id.'/settlements', [...$payment, 'submission_key' => (string) Str::uuid(), 'version' => 2, 'amount' => '6'])->assertOk();
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('paid', $account->fresh()->state);
        $this->assertSame(1000, (int) PaymentEntry::sum('amount_cents'));
        $this->get('/balance')->assertViewHas('cash', 1000)->assertViewHas('outstanding', 0);
        $this->get('/pending')->assertOk()->assertSee('Saldato')->assertDontSee('Acconto');
        $this->assertDatabaseHas('economic_audits', ['action' => 'pending.settled']);
    }

    public function test_outgoing_and_general_incoming_payments_are_counted_once(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->postJson('/pending', $this->payload('outgoing'))->assertOk();
        $account = PendingAccount::sole();
        $data = ['amount' => '10', 'method' => 'bank_transfer', 'note' => 'Saldo rimborso', 'submission_key' => (string) Str::uuid(), 'version' => 1];
        $this->postJson('/pending/'.$account->id.'/settlements', $data)->assertOk();
        $this->assertSame(1000, Expense::sole()->amount_cents);
        $this->delete('/expenses/'.Expense::sole()->id)->assertConflict();
        $this->postJson('/pending', $this->payload())->assertOk();
        $incoming = PendingAccount::latest('id')->first();
        $this->postJson('/pending/'.$incoming->id.'/settlements', [...$data, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->get('/balance')->assertOk()->assertViewHas('cash', 1000)->assertViewHas('spent', 1000)->assertViewHas('net', 0);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_pending_accounts_enforce_roles_validation_and_audited_changes(): void
    {
        $this->actingAs(User::factory()->create())->get('/pending')->assertForbidden();
        $this->postJson('/pending', $this->payload())->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->postJson('/pending', [...$this->payload(), 'amount' => '0'])->assertUnprocessable();
        $this->postJson('/pending', $this->payload())->assertOk();
        $account = PendingAccount::sole();
        $this->patchJson('/pending/'.$account->id, ['action' => 'update', 'version' => 1, 'subject' => 'Negozio aggiornato', 'description' => 'Nuova descrizione'])->assertOk();
        $this->patchJson('/pending/'.$account->id, ['action' => 'cancel', 'version' => 1])->assertConflict();
        $this->patchJson('/pending/'.$account->id, ['action' => 'cancel', 'version' => 2])->assertOk();
        $this->assertSame('cancelled', $account->fresh()->state);
        $this->assertDatabaseHas('economic_audits', ['action' => 'pending.cancel', 'user_id' => $admin->id]);
    }

    public function test_amount_changes_cannot_erase_settlements_or_cancel_a_partial_payment(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->postJson('/pending', $this->payload())->assertOk();
        $account = PendingAccount::sole();
        $change = ['action' => 'update', 'subject' => 'Fashion Test', 'description' => 'Servizio rivisto'];
        $this->patchJson('/pending/'.$account->id, [...$change, 'version' => 1, 'amount' => '12'])->assertOk();
        $this->assertSame(1200, $account->fresh()->amount_cents);
        $this->postJson('/pending/'.$account->id.'/settlements', ['version' => 2, 'amount' => '4', 'method' => 'cash', 'note' => 'Acconto', 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->patchJson('/pending/'.$account->id, [...$change, 'version' => 3, 'amount' => '3'])->assertUnprocessable();
        $this->patchJson('/pending/'.$account->id, ['action' => 'cancel', 'version' => 3])->assertUnprocessable();
        $this->patchJson('/pending/'.$account->id, [...$change, 'version' => 3, 'amount' => '6'])->assertOk();
        $this->assertSame(400, $account->fresh()->settled_cents);
        $this->assertSame(600, $account->fresh()->amount_cents);
        $this->get('/balance')->assertViewHas('cash', 400);
    }
}
