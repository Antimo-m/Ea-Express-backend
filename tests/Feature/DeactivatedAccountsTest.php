<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\DeactivatedAccounts;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeactivatedAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-01-01 10:00', 'Europe/Rome'));
    }

    public function test_deactivation_date_starts_and_resets_with_each_state_change(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'deactivate'])->assertSessionHasNoErrors();
        $this->assertTrue($rider->fresh()->deactivated_at->equalTo(now()));
        $this->get(route('users.index'))->assertSee('revisione di conservazione dal 01/04/2026');

        $this->travel(30)->days();
        $this->patch(route('users.update', $rider), ['action' => 'activate'])->assertSessionHasNoErrors();
        $this->assertNull($rider->fresh()->deactivated_at);

        $this->travel(10)->days();
        $this->patch(route('users.update', $rider), ['action' => 'deactivate'])->assertSessionHasNoErrors();
        $this->assertTrue($rider->fresh()->deactivated_at->equalTo(now()), 'Una nuova disattivazione genera una nuova decorrenza.');
    }

    public function test_disabled_accounts_cannot_authenticate(): void
    {
        $rider = User::factory()->create(['is_active' => false]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);
        $this->post('/login', ['email' => $rider->email, 'password' => 'password']);
        $this->assertGuest('web');
        $this->postJson('/api/v1/customer/auth/login', ['email' => $customer->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest('customer');
    }

    public function test_review_lists_only_accounts_past_the_period_and_never_changes_data(): void
    {
        $old = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false, 'name' => 'Cliente scaduto']);
        Order::factory()->create(['customer_id' => $old->id]);
        $this->travel(89)->days();
        $recent = User::factory()->create(['is_active' => false]);
        $this->assertSame(0, app(DeactivatedAccounts::class)->due()->count());

        $this->travel(2)->days();
        $due = app(DeactivatedAccounts::class)->due()->pluck('id')->all();
        $this->assertSame([$old->id], $due);
        $before = $old->fresh()->getAttributes();

        $this->artisan('accounts:review-deactivated')->expectsOutputToContain('Simulazione (dry-run)')->expectsOutputToContain('Anonimizzazione con conservazione')->assertSuccessful();
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertDatabaseHas('users', ['id' => $recent->id]);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_reactivation_stops_the_review_and_admins_are_never_listed(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);
        $this->travel(120)->days();
        $customer->is_active = true;
        $customer->save();
        $this->assertSame(0, app(DeactivatedAccounts::class)->due()->count());
        $this->assertNull(DeactivatedAccounts::reviewDate($customer));
        User::factory()->create(['role' => UserRole::Admin]);
        $this->assertSame(0, app(DeactivatedAccounts::class)->due()->where('role', UserRole::Admin->value)->count());
    }
}
