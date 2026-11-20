<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_sms_endpoints_are_unavailable(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/verify-phone')->assertNotFound();
        $this->post('/verify-phone/send', ['phone' => '+393331234567'])->assertNotFound();
        $this->post('/verify-phone/confirm', ['code' => '1234'])->assertNotFound();
    }

    public function test_otp_cannot_verify_another_account_or_redirect_email_delivery(): void
    {
        Notification::fake();
        $first = User::factory()->unverified()->create();
        $second = User::factory()->unverified()->create();
        $this->actingAs($first)->post('/verify-email-otp/send', ['email' => $second->email, 'user_id' => $second->id])->assertSessionHasNoErrors();
        Notification::assertSentTo($first, RiderEmailOtp::class);
        Notification::assertNotSentTo($second, RiderEmailOtp::class);
        $code = Notification::sent($first, RiderEmailOtp::class)->sole()->code;
        $this->actingAs($second)->post('/verify-email-otp/confirm', ['code' => $code, 'user_id' => $first->id])->assertSessionHasErrors('code');
        $this->assertNull($second->fresh()->email_verified_at);
        $this->actingAs($first)->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertNotNull($first->fresh()->email_verified_at);
    }

    public function test_otp_endpoints_require_login_and_customer_accounts_are_denied(): void
    {
        $this->post('/verify-email-otp/send')->assertRedirect(route('login'));
        $this->post('/verify-email-otp/confirm', ['code' => '123456'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))->post('/verify-email-otp/send')->assertForbidden();
    }

    public function test_failed_otp_is_not_flashed_or_exposed_in_user_serialization(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post('/verify-email-otp/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertNull(session()->getOldInput('code'));
        $this->assertArrayNotHasKey('email_otp_hash', $user->toArray());
    }

    public function test_email_delivery_failure_invalidates_the_challenge(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Private provider error'));
        $this->actingAs($user)->post('/verify-email-otp/send')->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertStringNotContainsString('Private provider error', session('errors')->first('code'));
    }
}
