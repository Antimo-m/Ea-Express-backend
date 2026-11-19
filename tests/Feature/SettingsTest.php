<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_save_preferences_without_changing_role_or_another_user(): void
    {
        $user = User::factory()->create(['role' => \App\UserRole::Admin]);
        $other = User::factory()->create(['role' => UserRole::Rider]);
        $this->actingAs($user)->patch('/settings', ['notify_orders' => 0, 'notify_messages' => 1, 'id' => $other->id, 'role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->notify_orders);
        $this->assertTrue($user->fresh()->notify_messages);
        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertTrue($other->fresh()->notify_orders);
        $this->get('/settings')->assertOk()->assertSee('Notifiche nel gestionale');
    }

    public function test_preferences_require_boolean_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => \App\UserRole::Admin]))->patch('/settings', ['notify_orders' => 'bad'])->assertSessionHasErrors(['notify_orders', 'notify_messages']);
    }
}
