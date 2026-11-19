<?php

namespace Tests\Feature;

use App\Models\PendingAccount;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPendingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_only_own_pending_accounts_from_customer_perspective(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $mine = PendingAccount::factory()->create(['customer_id' => $customer->id, 'amount_cents' => 2500, 'direction' => 'incoming', 'notes' => 'Nota amministrativa privata']);
        PendingAccount::factory()->create(['customer_id' => $customer->id, 'amount_cents' => 700, 'direction' => 'outgoing']);
        $hidden = PendingAccount::factory()->create(['customer_id' => $other->id, 'subject' => 'Conto altro cliente']);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending?customer_id='.$other->slug)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('totals.incoming', 2500)->assertJsonPath('totals.outgoing', 700)->assertJsonMissing(['subject' => $hidden->subject])->assertDontSee('Nota amministrativa privata');
        $this->getJson('/api/v1/customer/pending/'.$hidden->slug)->assertNotFound();
        $this->postJson('/api/v1/customer/pending', ['amount_cents' => 1])->assertMethodNotAllowed();
        $this->patchJson('/api/v1/customer/pending/'.$mine->slug, ['amount_cents' => 1])->assertMethodNotAllowed();
        $this->assertSame(2500, $mine->fresh()->amount_cents);
        $this->actingAs($customer)->getJson('/settings/users')->assertForbidden();
        $this->getJson('/orders/in-progress')->assertForbidden();
    }

    public function test_staff_cannot_use_customer_pending_api(): void
    {
        foreach ([UserRole::Admin, UserRole::Rider] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'customer')->getJson('/api/v1/customer/pending')->assertForbidden();
            $this->getJson('/api/v1/customer/pending/1')->assertForbidden();
        }
    }
}
