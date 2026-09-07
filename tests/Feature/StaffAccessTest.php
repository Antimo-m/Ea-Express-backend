<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_closed_on_both_endpoints(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'outsider@example.com'])->assertNotFound();
        $this->get('/login')->assertDontSee('Registrati');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_customer_cannot_enter_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))->get('/dashboard')->assertForbidden();
    }

    public function test_unverified_rider_is_redirected_until_email_is_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->get('/verify-email')->assertOk();
        $user->markEmailAsVerified();
        $this->get('/dashboard')->assertOk();
    }
}
