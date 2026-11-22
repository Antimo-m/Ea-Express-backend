<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetForLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function seedTestActivity(): ShippingRate
    {
        $rate = ShippingRate::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['shipping_rate_id' => $rate->id, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 700, 'rider_id' => $admin->id]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Storno di prova'])->assertOk();
        PendingAccount::factory()->create();
        $this->assertGreaterThan(0, PaymentEntry::whereNotNull('original_entry_id')->count());

        return $rate;
    }

    public function test_simulation_changes_nothing(): void
    {
        $this->seedTestActivity();
        $users = User::count();

        $this->artisan('app:reset-for-launch')->expectsOutputToContain('Simulazione')->assertSuccessful();

        $this->assertSame($users, User::count());
        $this->assertSame(1, Order::count());
    }

    public function test_apply_requires_the_confirmation_word(): void
    {
        $this->seedTestActivity();
        $users = User::count();

        $this->artisan('app:reset-for-launch --apply')->expectsQuestion('Operazione irreversibile. Scrivi AZZERA per eliminare i dati', 'si')->assertFailed();

        $this->assertSame($users, User::count());
        $this->assertSame(1, Order::count());
    }

    public function test_apply_clears_accounts_and_activity_but_keeps_rates_and_accounting_control(): void
    {
        $rate = $this->seedTestActivity();
        $controls = DB::table('accounting_controls')->count();

        $this->artisan('app:reset-for-launch --apply')->expectsQuestion('Operazione irreversibile. Scrivi AZZERA per eliminare i dati', 'AZZERA')->assertSuccessful();

        foreach (['users', 'orders', 'payment_entries', 'receipt_reversals', 'pending_accounts', 'economic_audits', 'notifications', 'order_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertTrue(ShippingRate::whereKey($rate->id)->exists());
        $this->assertSame($controls, DB::table('accounting_controls')->count());
        $this->assertGreaterThan(0, DB::table('migrations')->count());

        $this->artisan('app:create-rider', ['email' => 'titolare@example.test', 'name' => 'Titolare'])->expectsQuestion('Password iniziale (almeno 12 caratteri)', 'Password-Lunga-2026!')->assertSuccessful();
        $this->artisan('app:user-role', ['email' => 'titolare@example.test', 'role' => 'admin'])->assertSuccessful();
        $this->assertSame(UserRole::Admin, User::sole()->role);
    }
}
