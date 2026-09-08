<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DevelopmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_login_skips_otp_without_marking_phone_verified(): void
    {
        config(['sms.verification_enabled' => false]);
        $user = User::factory()->phoneUnverified()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk();
        $this->get('/settings')->assertOk()->assertSee('Accesso sviluppo senza OTP');
        $this->get('/verify-phone')->assertRedirect(route('dashboard'));
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertNull($user->fresh()->phone);
    }

    public function test_disabled_sms_blocks_endpoints_and_gateway_without_sending(): void
    {
        config(['sms.verification_enabled' => false]);
        Http::fake();
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->post('/verify-phone/send', ['phone' => '+393331234567'])->assertNotFound();
        $this->post('/verify-phone/confirm', ['code' => '1234'])->assertNotFound();
        try {
            (new SmsGateway)->sendOtp('+393331234567', '1234');
            $this->fail('SMS gateway should be disabled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disattivato', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertNull($user->fresh()->phone_otp_hash);
    }

    public function test_production_requires_verification_even_if_local_flag_was_copied(): void
    {
        config(['sms.verification_enabled' => false]);
        $user = User::factory()->phoneUnverified()->create();
        $this->app['env'] = 'production';
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('phone.notice'));
        $this->get('/verify-phone')->assertOk();
    }

    public function test_reenabling_verification_blocks_unverified_rider_and_keeps_permissions(): void
    {
        config(['sms.verification_enabled' => false]);
        $user = User::factory()->phoneUnverified()->create();
        $this->actingAs($user)->get('/settings/users')->assertForbidden();
        config(['sms.verification_enabled' => true]);
        $this->get('/dashboard')->assertRedirect(route('phone.notice'));
    }
}
