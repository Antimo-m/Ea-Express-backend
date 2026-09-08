<?php

namespace Tests\Feature;

use App\Actions\SendPhoneOtp;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sms.twilio.account_sid' => 'AC'.str_repeat('a', 32), 'sms.twilio.auth_token' => 'test-token', 'sms.twilio.from' => '+15005550006']);
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test', 'status' => 'queued'], 201)]);
    }

    private function sentCode(): string
    {
        $body = Http::recorded()->last()[0]['Body'];
        preg_match('/verifica e (\d{4})\./', $body, $match);

        return $match[1];
    }

    public function test_first_login_requires_phone_and_successful_otp_is_persisted_for_future_logins(): void
    {
        $user = User::factory()->phoneUnverified()->unverified()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('phone.notice'));
        $this->get('/dashboard')->assertRedirect(route('phone.notice'));
        $this->get('/verify-phone')->assertOk()->assertSee('Verifica il tuo cellulare');
        $this->post('/verify-phone/send', ['phone' => '333 1234567'])->assertSessionHasNoErrors();
        $code = $this->sentCode();
        $user->refresh();
        $this->assertMatchesRegularExpression('/^\d{4}$/D', $code);
        $this->assertNotSame($code, $user->phone_otp_hash);
        $this->assertTrue(Hash::check(SendPhoneOtp::digest($user->id, '+393331234567', $code), $user->phone_otp_hash));
        $this->assertSame(900, (int) $user->phone_otp_sent_at->diffInSeconds($user->phone_otp_expires_at));
        $this->post('/verify-phone/confirm', ['code' => $code])->assertRedirect(route('dashboard'));
        $user->refresh();
        $this->assertSame('+393331234567', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->phone_otp_hash);
        $this->assertNull($user->phone_otp_expires_at);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk();
        $this->get('/verify-phone')->assertRedirect(route('dashboard'));
        $this->post('/verify-phone/confirm', ['code' => $code])->assertSessionHasErrors('code');
        Http::assertSentCount(1);
    }

    public function test_wrong_codes_are_counted_and_fifth_failure_invalidates_the_challenge(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasNoErrors();
        $code = $this->sentCode();
        $wrong = $code === '0000' ? '0001' : '0000';
        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify-phone/confirm', ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, $user->fresh()->phone_otp_attempts);
        $this->assertNull($user->fresh()->phone_otp_hash);
        $this->travel(61)->seconds();
        $this->post('/verify-phone/confirm', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_code_expires_at_fifteen_minutes(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasNoErrors();
        $code = $this->sentCode();
        $this->travel(15)->minutes();
        $this->post('/verify-phone/confirm', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertNull($user->fresh()->phone_otp_hash);
    }

    public function test_resend_has_cooldown_and_invalidates_old_code(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasNoErrors();
        $old = $this->sentCode();
        $this->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasErrors('phone');
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->post('/verify-phone/send', ['phone' => '+393331234568'])->assertSessionHasNoErrors();
        $code = $this->sentCode();
        $this->assertSame('+393331234568', $user->fresh()->pending_phone);
        $this->assertFalse(Hash::check(SendPhoneOtp::digest($user->id, '+393331234567', $old), $user->fresh()->phone_otp_hash));
        $this->post('/verify-phone/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertSame('+393331234568', $user->fresh()->phone);
    }

    public function test_otp_cannot_verify_another_account_and_verified_number_is_unique(): void
    {
        $first = User::factory()->phoneUnverified()->create();
        $second = User::factory()->phoneUnverified()->create();
        $this->actingAs($first)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasNoErrors();
        $code = $this->sentCode();
        $this->actingAs($second)->post('/verify-phone/confirm', ['code' => $code, 'user_id' => $first->id])->assertSessionHasErrors('code');
        $this->actingAs($first)->post('/verify-phone/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $this->actingAs($second)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasErrors('phone');
        $this->assertNull($second->fresh()->phone_verified_at);
    }

    public function test_sms_failure_never_leaves_a_valid_challenge_or_reports_success(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::response(['error' => 'provider response'], 500)]);
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasErrors('phone');
        $this->assertNull($user->fresh()->phone_otp_hash);
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_otp_endpoints_require_login_and_limit_repeated_sends(): void
    {
        $this->post('/verify-phone/send', ['phone' => '+393331234567'])->assertRedirect(route('login'));
        $this->post('/verify-phone/confirm', ['code' => '1234'])->assertRedirect(route('login'));
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasNoErrors();
            $this->travel(61)->seconds();
        }
        $this->post('/verify-phone/send', ['phone' => '+393331234567'])->assertTooManyRequests();
        Http::assertSentCount(3);
    }

    public function test_administrator_can_access_without_phone_or_email_verification(): void
    {
        $user = User::factory()->phoneUnverified()->unverified()->create(['role' => UserRole::Admin]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk();
        $this->get('/settings/users')->assertOk();
        $this->assertNull($user->fresh()->phone_verified_at);
        Http::assertNothingSent();
    }

    public function test_missing_sms_configuration_and_connection_failures_do_not_verify_accounts(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        config(['sms.twilio.auth_token' => null]);
        $this->actingAs($user)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasErrors('phone');
        Http::assertNothingSent();
        $this->assertNull($user->fresh()->phone_otp_hash);
        config(['sms.twilio.auth_token' => 'test-token']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::failedConnection()]);
        $this->travel(61)->seconds();
        $this->post('/verify-phone/send', ['phone' => '+393331234567'])->assertSessionHasErrors('phone');
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertNull($user->fresh()->phone_otp_hash);
    }

    public function test_failed_otp_is_not_flashed_back_into_session(): void
    {
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/verify-phone/confirm', ['code' => '1234'])->assertSessionHasErrors('code');
        $this->assertNull(session()->getOldInput('code'));
        $this->assertArrayNotHasKey('phone_otp_hash',$user->toArray());
    }
}
