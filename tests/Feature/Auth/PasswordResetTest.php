<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_recovery_screens_are_available_to_guests(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Password dimenticata?')->assertSee('gestita soltanto dal server');
        $this->get('/reset-password/abc123?email=rider%40example.test')->assertOk()->assertSee('rider@example.test')->assertSee('name="token" value="abc123"', false);
    }

    public function test_login_offers_password_recovery(): void
    {
        $this->get('/login')->assertSee('Password dimenticata?')->assertSee(route('password.request'), false);
    }
}
