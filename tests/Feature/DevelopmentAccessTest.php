<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_rider_cannot_bypass_email_verification_in_any_environment(): void
    {
        $user = User::factory()->unverified()->create();
        foreach (['local', 'testing', 'production'] as $environment) {
            $this->app['env'] = $environment;
            $this->actingAs($user)->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        }
    }

    public function test_verified_rider_does_not_need_phone_verification(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->get('/settings/users')->assertForbidden();
    }

    public function test_admin_is_required_to_complete_email_otp(): void
    {
        $admin = User::factory()->unverified()->phoneUnverified()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        $this->get('/settings/users')->assertRedirect(route('email-otp.notice'));
    }
}
