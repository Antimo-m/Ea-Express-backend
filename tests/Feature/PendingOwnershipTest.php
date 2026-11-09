<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function payload(User $customer): array
    {
        return ['customer_id' => $customer->slug, 'subject' => 'Servizio cliente', 'description' => 'Consegne concordate', 'amount' => '20', 'direction' => 'incoming', 'occurred_on' => now()->toDateString()];
    }

    public function test_admin_created_accounts_are_isolated_in_lists_details_and_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $mario = User::factory()->create(['role' => UserRole::Customer]);
        $luca = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($admin)->postJson('/pending', $this->payload($mario))->assertOk();
        $mine = PendingAccount::sole();
        $this->postJson('/pending', [...$this->payload($luca), 'direction' => 'outgoing', 'amount' => '15'])->assertOk();
        $other = PendingAccount::latest('id')->first();
        $this->assertTrue($mine->customer->is($mario));
        $this->assertSame($mine->id, $mario->pendingAccounts()->sole()->id);
        $this->actingAs($mario, 'customer')->getJson('/api/v1/customer/pending?customer_id='.$luca->slug)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $mine->slug)->assertJsonPath('data.0.direction', 'incoming')->assertJsonPath('data.0.direction_label', 'Devi pagare EA-Express')->assertJsonPath('totals.incoming', 2000)->assertJsonPath('totals.outgoing', 0);
        $this->getJson('/api/v1/customer/pending/'.$other->slug)->assertNotFound();
        $this->actingAs($luca, 'customer')->getJson('/api/v1/customer/pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $other->slug)->assertJsonPath('data.0.direction', 'outgoing')->assertJsonPath('totals.outgoing', 1500);
        $this->getJson('/api/v1/customer/pending/'.$mine->slug)->assertNotFound();
        $this->actingAs($admin, 'web')->postJson('/pending/'.$mine->slug.'/settlements', ['amount' => '20', 'version' => 1, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame($mario->id, $mine->fresh()->customer_id);
        $this->actingAs($mario, 'customer')->getJson('/api/v1/customer/pending?view=history&customer_id='.$luca->slug)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $mine->slug)->assertJsonPath('data.0.state', 'paid')->assertJsonPath('totals.incoming', 0);
        $this->getJson('/api/v1/customer/pending?view=open')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer/pending/'.$mine->slug)->assertOk()->assertJsonPath('data.slug', $mine->slug)->assertJsonPath('data.settlements.0.amount_cents', 2000)->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.settlements.0.user_id');
        $this->actingAs($luca, 'customer')->getJson('/api/v1/customer/pending?view=history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer/pending/'.$mine->slug)->assertNotFound();
    }

    public function test_creation_requires_a_real_customer_and_coherent_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $payload = $this->payload($customer);
        $this->actingAs($admin);
        foreach ([null, '', $admin->id, $rider->id, 999999, 'invalid'] as $invalid) {
            $this->postJson('/pending', [...$payload, 'customer_id' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        }
        unset($payload['customer_id']);
        $this->postJson('/pending', $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/pending', [...$this->payload($customer), 'state' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->postJson('/pending', [...$this->payload($customer), 'direction' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('direction');
        $order = Order::factory()->create(['customer_id' => User::factory()->create(['role' => UserRole::Customer])->id, 'status' => OrderStatus::Delivered, 'price_cents' => 2000]);
        $this->postJson('/pending', [...$this->payload($customer), 'order_id' => $order->slug])->assertUnprocessable();
        $this->assertDatabaseCount('pending_accounts', 0);
    }

    public function test_admin_sees_customer_identity_filters_and_customer_only_choices(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $a = User::factory()->create(['role' => UserRole::Customer, 'business_description' => 'Boutique cliente A']);
        $b = User::factory()->create(['role' => UserRole::Customer]);
        PendingAccount::factory()->create(['customer_id' => $a->id, 'direction' => 'incoming']);
        PendingAccount::factory()->create(['customer_id' => $b->id, 'direction' => 'outgoing']);
        $this->actingAs($admin)->get('/pending')->assertOk()->assertSee($a->email)->assertSee($b->email)->assertSee('Boutique cliente A')->assertSee('il cliente deve pagare EA-Express')->assertSee('EA-Express deve pagare il cliente')->assertViewHas('customers', fn ($customers) => $customers->pluck('id')->sort()->values()->all() === [$a->id, $b->id])->assertDontSee($rider->email);
        $this->get('/pending?customer_id='.$a->slug)->assertOk()->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 1 && $accounts->first()->customer_id === $a->id);
        $this->get('/pending?q='.urlencode($b->email))->assertOk()->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 1 && $accounts->first()->customer_id === $b->id);
    }

    public function test_legacy_records_stay_private_until_explicit_audited_assignment_and_cannot_be_reassigned(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $legacy = PendingAccount::factory()->create(['customer_id' => null, 'state' => 'cancelled']);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending?view=history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer/pending/'.$legacy->slug)->assertNotFound();
        $this->actingAs($admin, 'web')->get('/pending?state=all')->assertOk()->assertSee('Cliente non associato')->assertSee('Associa cliente');
        $this->patchJson('/pending/'.$legacy->slug, ['action' => 'assign', 'version' => 1, 'customer_id' => $admin->slug])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->patchJson('/pending/'.$legacy->slug, ['action' => 'assign', 'version' => 1, 'customer_id' => $customer->slug])->assertOk();
        $this->assertDatabaseHas('pending_accounts', ['id' => $legacy->id, 'customer_id' => $customer->id, 'state' => 'cancelled', 'version' => 2]);
        $this->assertDatabaseHas('economic_audits', ['action' => 'pending.assigned', 'entity_id' => $legacy->id]);
        $this->patchJson('/pending/'.$legacy->slug, ['action' => 'assign', 'version' => 2, 'customer_id' => $other->slug])->assertConflict();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending?view=history')->assertOk()->assertJsonPath('data.0.slug', $legacy->slug)->assertJsonPath('data.0.remaining_cents', 0);
        $this->actingAs($other, 'customer')->getJson('/api/v1/customer/pending/'.$legacy->slug)->assertNotFound();
    }

    public function test_updates_cannot_change_owner_and_cancellation_preserves_owner(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($admin)->patchJson('/pending/'.$account->slug, ['action' => 'update', 'version' => 1, 'subject' => 'Nuovo soggetto', 'description' => 'Descrizione', 'customer_id' => $other->slug])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->patchJson('/pending/'.$account->slug, ['action' => 'cancel', 'version' => 1])->assertOk();
        $this->assertDatabaseHas('pending_accounts', ['id' => $account->id, 'customer_id' => $customer->id, 'state' => 'cancelled']);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending?view=history')->assertOk()->assertJsonPath('data.0.slug', $account->slug);
    }

    public function test_customer_cannot_mutate_accounts_through_portal_or_admin_endpoints(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id]);
        $payment = $account->settlements()->create(['user_id' => $account->created_by, 'amount_cents' => 100, 'method' => 'cash', 'note' => 'Privata', 'submission_key' => (string) Str::uuid()]);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/pending', $this->payload($customer))->assertMethodNotAllowed();
        $this->patchJson('/api/v1/customer/pending/'.$account->slug, ['amount' => '1'])->assertMethodNotAllowed();
        $this->deleteJson('/api/v1/customer/pending/'.$account->slug)->assertMethodNotAllowed();
        $this->postJson('/api/v1/customer/pending/'.$account->slug.'/settlements', [])->assertNotFound();
        $this->actingAs($customer, 'web')->postJson('/pending', $this->payload($customer))->assertForbidden();
        $this->patchJson('/pending/'.$account->slug, ['action' => 'cancel', 'version' => 1])->assertForbidden();
        $this->postJson('/pending/'.$account->slug.'/settlements', [])->assertForbidden();
        $this->patchJson('/pending/'.$account->slug.'/settlements/'.$payment->slug, [])->assertForbidden();
        $this->getJson('/economic-audits?type=pending_accounts&slug='.$account->slug)->assertForbidden();
        $this->assertSame(1000, $account->fresh()->amount_cents);
        $this->assertSame('open', $account->fresh()->state);
    }

    public function test_guests_and_inactive_customers_cannot_read_pending_accounts(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id]);
        $this->getJson('/api/v1/customer/pending')->assertUnauthorized();
        $this->getJson('/api/v1/customer/pending/'.$account->slug)->assertUnauthorized();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending')->assertUnauthorized();
        $this->getJson('/api/v1/customer/pending/'.$account->slug)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web')->get('/pending')->assertOk()->assertSee($customer->email)->assertSee('Account disattivato');
    }

    public function test_deleting_customer_cannot_erase_accounting_history(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id, 'state' => 'paid', 'settled_cents' => 1000]);
        try {
            $customer->delete();
            $this->fail('Un cliente con storico contabile non deve essere eliminato.');
        } catch (QueryException) {
            $this->assertDatabaseHas('pending_accounts', ['id' => $account->id, 'customer_id' => $customer->id]);
            $this->assertDatabaseHas('users', ['id' => $customer->id]);
        }
    }

    public function test_legacy_assignment_checks_order_ownership_and_version_without_changing_financial_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $legacy = PendingAccount::factory()->create(['customer_id' => null, 'order_id' => $order->id, 'state' => 'paid', 'settled_cents' => 1000]);
        $this->actingAs($admin)->patchJson('/pending/'.$legacy->slug, ['action' => 'assign', 'version' => 1, 'customer_id' => $other->slug])->assertUnprocessable();
        $this->patchJson('/pending/'.$legacy->slug, ['action' => 'assign', 'version' => 2, 'customer_id' => $customer->slug])->assertConflict();
        $this->patchJson('/pending/'.$legacy->slug, ['action' => 'assign', 'version' => 1, 'customer_id' => $customer->slug])->assertOk();
        $this->assertDatabaseHas('pending_accounts', ['id' => $legacy->id, 'customer_id' => $customer->id, 'order_id' => $order->id, 'state' => 'paid', 'amount_cents' => 1000, 'settled_cents' => 1000]);
    }

    public function test_pagination_and_direction_filters_do_not_leak_other_customers(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PendingAccount::factory()->count(21)->create(['customer_id' => $customer->id, 'created_by' => $admin->id]);
        PendingAccount::factory()->create(['customer_id' => $other->id, 'created_by' => $admin->id, 'subject' => 'Privato altro cliente']);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending?page=2&direction=incoming')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totals.incoming', 21000)->assertJsonMissing(['subject' => 'Privato altro cliente']);
        $this->getJson('/api/v1/customer/pending?direction=outgoing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer/pending?view=invalid')->assertUnprocessable();
    }
}
