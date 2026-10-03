<?php

namespace Tests\Feature;

use App\Actions\SendEmailOtp;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RiderEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_sends_email_and_requires_one_time_verification(): void
    {
        Notification::fake();
        $rider = User::factory()->unverified()->create();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        Notification::assertSentTo($rider, RiderEmailOtp::class);
        $code = Notification::sent($rider, RiderEmailOtp::class)->sole()->code;
        $this->assertNotSame($code, $rider->fresh()->email_otp_hash);
        $this->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        $this->getJson('/orders/in-progress')->assertForbidden();
        $this->get('/verify-email-otp')->assertOk()->assertSee($rider->email);
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertNotNull($rider->fresh()->email_verified_at);
        $this->assertNull($rider->fresh()->email_otp_hash);
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasErrors('code');
        $this->post('/logout');
        $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect('/dashboard');
        Notification::assertSentToTimes($rider, RiderEmailOtp::class, 1);
    }

    public function test_resend_cooldown_invalidates_previous_code_and_limits_hourly_sends(): void
    {
        Notification::fake();
        $rider = User::factory()->unverified()->create();
        $this->actingAs($rider)->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $old = Notification::sent($rider, RiderEmailOtp::class)->first()->code;
        $this->post('/verify-email-otp/send')->assertSessionHasErrors('code');
        $this->travel(61)->seconds();
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $this->post('/verify-email-otp/confirm', ['code' => $old])->assertSessionHasErrors('code');
        $this->travel(61)->seconds();
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $this->travel(61)->seconds();
        $this->post('/verify-email-otp/send')->assertSessionHasErrors('code');
        Notification::assertSentToTimes($rider, RiderEmailOtp::class, 3);
    }

    public function test_five_wrong_attempts_invalidate_code_and_expired_codes_fail(): void
    {
        Notification::fake();
        $rider = User::factory()->unverified()->create();
        app(SendEmailOtp::class)->handle($rider);
        $valid = Notification::sent($rider, RiderEmailOtp::class)->first()->code;
        $this->actingAs($rider);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify-email-otp/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, $rider->fresh()->email_otp_attempts);
        $this->assertNull($rider->fresh()->email_otp_hash);
        $this->travel(61)->seconds();
        $this->post('/verify-email-otp/confirm', ['code' => $valid])->assertSessionHasErrors('code');
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $valid = Notification::sent($rider, RiderEmailOtp::class)->last()->code;
        $this->travel(16)->minutes();
        $this->post('/verify-email-otp/confirm', ['code' => $valid])->assertSessionHasErrors('code');
        $this->assertNull($rider->fresh()->email_verified_at);
        Notification::assertSentToTimes($rider, RiderEmailOtp::class, 2);
    }

    public function test_admin_can_reset_verification_and_email_changes_invalidate_otp(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'reset-email'])->assertSessionHasNoErrors();
        $this->assertNull($rider->fresh()->email_verified_at);
        $this->actingAs($rider->fresh())->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $code = Notification::sent($rider, RiderEmailOtp::class)->first()->code;
        $rider->forceFill(['email' => 'changed@example.com'])->save();
        $this->actingAs($rider->fresh())->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasErrors('code');
        Notification::assertSentToTimes($rider, RiderEmailOtp::class, 1);
    }
}
