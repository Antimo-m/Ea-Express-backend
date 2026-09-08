<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_rider_without_allowing_role_or_verification_injection(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/settings')->assertSee('Gestione utenti');
        $this->get('/settings/users/create')->assertOk();
        $this->post('/settings/users', ['name' => 'Nuovo Rider', 'email' => 'rider@example.com', 'password' => 'PasswordLunga123', 'password_confirmation' => 'PasswordLunga123', 'role' => 'admin', 'phone_verified_at' => now()])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $rider = User::where('email', 'rider@example.com')->sole();
        $this->assertSame(UserRole::Rider, $rider->role);
        $this->assertNull($rider->phone_verified_at);
        $this->assertTrue(Hash::check('PasswordLunga123', $rider->password));
        $this->get('/settings/users')->assertSee('Nuovo Rider')->assertDontSee('PasswordLunga123');
    }

    public function test_rider_cannot_list_create_or_modify_profiles(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->get('/settings')->assertDontSee('Gestione utenti');
        $this->get('/settings/users')->assertForbidden();
        $this->get('/settings/users/create')->assertForbidden();
        $this->post('/settings/users', [])->assertForbidden();
        $this->patch(route('users.update', $other), ['action' => 'deactivate'])->assertForbidden();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_disabled_account_cannot_login_or_continue_an_existing_session(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'deactivate'])->assertSessionHasNoErrors();
        $this->assertFalse($rider->fresh()->is_active);
        $this->actingAs($rider->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_cannot_disable_another_admin_or_rider_with_active_deliveries(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted]);
        $this->actingAs($admin)->patch(route('users.update', $admin), ['action' => 'deactivate'])->assertForbidden();
        $this->patch(route('users.update', $rider), ['action' => 'deactivate'])->assertSessionHasErrors('action');
        $this->assertTrue($rider->fresh()->is_active);
    }

    public function test_reset_phone_removes_verification_and_forces_a_new_first_access_check(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $token = $rider->remember_token;
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'reset-phone'])->assertSessionHasNoErrors();
        $this->assertNull($rider->fresh()->phone_verified_at);
        $this->assertNotSame($token, $rider->fresh()->remember_token);
        $this->actingAs($rider->fresh())->get('/dashboard')->assertRedirect(route('phone.notice'));
    }
}
