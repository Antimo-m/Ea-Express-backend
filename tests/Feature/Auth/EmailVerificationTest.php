<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_is_removed(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/verify-email')->assertNotFound();
    }

    public function test_email_verification_links_are_no_longer_accepted(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_cannot_be_requested(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->post('/email/verification-notification')->assertNotFound();
    }
}
