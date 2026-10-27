<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_screens_are_no_longer_accessible(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/reset-password/old-token')->assertNotFound();
    }

    public function test_login_does_not_offer_self_service_password_recovery(): void
    {
        $this->get('/login')->assertDontSee('Password dimenticata')->assertDontSee('forgot-password');
    }
}
