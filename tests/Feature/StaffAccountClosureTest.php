<?php

namespace Tests\Feature;

use App\Actions\CloseStaffAccount;
use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Eliminazione degli account dello staff: il Rider dall'Admin, l'Admin dal proprio profilo.
 * Lo storico (ordini, pagamenti, audit) resta; credenziali, contatti e sessioni no.
 */
class StaffAccountClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_deletes_a_rider_keeping_orders_payments_and_audit_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['name' => 'Rider Storico']);
        $delivered = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'price_cents' => 700]);
        $payment = new PaymentEntry;
        $payment->forceFill(['order_id' => $delivered->id, 'user_id' => $rider->id, 'amount_cents' => 700, 'kind' => 'receipt', 'note' => ''])->save();
        $originalEmail = $rider->email;

        $this->actingAs($admin)->get('/settings/users')->assertSee('Rider Storico')->assertSee('Elimina account');
        $this->delete(route('users.destroy', $rider))->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $closed = $rider->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertFalse($closed->is_active);
        $this->assertSame(UserRole::Rider, $closed->role);
        $this->assertNotSame($originalEmail, $closed->email);
        $this->assertStringEndsWith('@account.invalid', $closed->email);
        $this->assertSame('Rider Storico', $closed->name, 'Il nome resta come riferimento dello storico.');
        $this->assertSame($rider->id, $delivered->fresh()->rider_id);
        $this->assertSame($rider->id, $payment->fresh()->user_id);
        $this->assertTrue(EconomicAudit::where('action', 'user.closed')->where('entity_id', $rider->id)->exists());
        $this->get('/settings/users')->assertDontSee('Rider Storico');

        $this->post('/logout');
        $this->post('/login', ['email' => $originalEmail, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_existing_rider_session_ends_when_the_account_is_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->actingAs($rider)->get('/dashboard')->assertOk();
        app(CloseStaffAccount::class)->handle($admin, $rider);
        $this->app['auth']->forgetGuards(); // come in una nuova richiesta reale: l'utente si rilegge dal database

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_rider_with_open_deliveries_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);

        $this->actingAs($admin)->from('/settings/users')->delete(route('users.destroy', $rider))->assertRedirect('/settings/users')->assertSessionHasErrors('account');
        $this->assertNull($rider->fresh()->closed_at);
        $this->assertTrue($rider->fresh()->is_active);
    }

    public function test_riders_cannot_delete_accounts_including_their_own(): void
    {
        $rider = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($rider)->delete(route('users.destroy', $other))->assertForbidden();
        $this->delete('/profile', ['password' => 'password', 'confirmation' => 'ELIMINA'])->assertForbidden();
        $this->get('/profile')->assertOk()->assertDontSee('Elimina il tuo account')->assertSee('rivolgiti all’amministratore');
        $this->assertNull($rider->fresh()->closed_at);
        $this->assertNull($other->fresh()->closed_at);
    }

    public function test_admin_cannot_delete_another_admin_or_a_customer_from_the_rider_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($admin)->delete(route('users.destroy', $customer))->assertForbidden();
        $this->delete(route('users.destroy', $admin))->assertForbidden();
        $this->assertNull($customer->fresh()->closed_at);
        $this->assertNull($admin->fresh()->closed_at);
    }

    public function test_admin_closes_own_account_with_password_and_explicit_confirmation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin Uscente']);
        $order = Order::factory()->create(['created_by' => $admin->id, 'status' => OrderStatus::Delivered]);

        $this->actingAs($admin)->get('/profile')->assertSee('Elimina il tuo account');
        $this->from('/profile')->delete('/profile', ['password' => 'sbagliata', 'confirmation' => 'ELIMINA'])->assertRedirect('/profile')->assertSessionHasErrorsIn('accountClosure', 'password');
        $this->from('/profile')->delete('/profile', ['password' => 'password', 'confirmation' => 'elimina'])->assertSessionHasErrorsIn('accountClosure', 'confirmation');
        $this->assertNull($admin->fresh()->closed_at);

        $this->delete('/profile', ['password' => 'password', 'confirmation' => 'ELIMINA'])->assertRedirect(route('login'));
        $this->assertGuest();
        $closed = $admin->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertNotSame(UserRole::Admin, $closed->role, 'Il posto di Admin si libera.');
        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
        $this->assertSame($admin->id, $order->fresh()->created_by);
        $this->assertTrue(EconomicAudit::where('action', 'account.closed_by_owner')->where('entity_id', $admin->id)->exists());
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_admin_with_open_deliveries_assigned_must_reassign_them_first(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::PickedUp]);

        $this->actingAs($admin)->from('/profile')->delete('/profile', ['password' => 'password', 'confirmation' => 'ELIMINA'])->assertSessionHasErrorsIn('accountClosure', 'account');
        $this->assertNull($admin->fresh()->closed_at);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_after_admin_closure_a_new_admin_is_appointed_only_from_the_server(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $successor = User::factory()->create(['email' => 'nuovo-admin@example.com']);
        $this->actingAs($admin)->delete('/profile', ['password' => 'password', 'confirmation' => 'ELIMINA']);

        $this->post('/register', ['name' => 'Intruso', 'email' => 'intruso@example.com', 'password' => 'PasswordLunga123!', 'password_confirmation' => 'PasswordLunga123!', 'role' => 'admin']);
        $this->assertSame(0, User::where('role', UserRole::Admin)->count(), 'Nessuna pagina pubblica crea un Admin.');

        $this->assertSame(1, Artisan::call('app:user-role', ['email' => $admin->fresh()->email, 'role' => 'admin']), 'Un account eliminato non torna Admin.');
        $this->assertSame(0, Artisan::call('app:user-role', ['email' => 'nuovo-admin@example.com', 'role' => 'admin']));
        $this->assertSame(UserRole::Admin, $successor->fresh()->role);
    }
}
