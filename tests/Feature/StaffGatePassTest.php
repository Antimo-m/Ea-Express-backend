<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffGatePassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_security.staff_gate.pass' => 'gate-pass-for-tests']);
        Notification::fake();
    }

    /** @return array<string, array{UserRole}> */
    public static function staffRoles(): array
    {
        return ['admin' => [UserRole::Admin], 'rider' => [UserRole::Rider]];
    }

    #[DataProvider('staffRoles')]
    public function test_login_renews_the_pass_for_180_days_only_after_the_otp(UserRole $role): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => $role]);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertCookieMissing('ea_staff');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'))->assertCookieMissing('ea_staff');
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $this->post(route('email-otp.verify'), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code')->assertCookieMissing('ea_staff');
        $response = $this->post(route('email-otp.verify'), ['code' => $code])->assertRedirect(route('dashboard'))->assertCookie('ea_staff', 'gate-pass-for-tests', encrypted: false);

        $this->assertSame(now()->addDays(180)->getTimestamp(), $response->getCookie('ea_staff', decrypt: false)->getExpiresTime());
    }

    public function test_trusted_device_login_without_otp_also_renews_the_pass(): void
    {
        $rider = User::factory()->create();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password']);
        $device = $this->post(route('email-otp.verify'), ['code' => Notification::sent($rider, RiderEmailOtp::class)->last()->code])->getCookie('ea_trusted_rider')->getValue();
        $this->post('/logout');
        Cookie::flushQueuedCookies();

        $this->withCookie('ea_trusted_rider', $device)->post('/login', ['email' => $rider->email, 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertCookie('ea_staff', 'gate-pass-for-tests', encrypted: false);
        Notification::assertSentToTimes($rider, RiderEmailOtp::class, 1);
    }

    public function test_login_works_without_a_configured_pass(): void
    {
        config(['auth_security.staff_gate.pass' => null]);
        $rider = User::factory()->create();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password']);

        $this->post(route('email-otp.verify'), ['code' => Notification::sent($rider, RiderEmailOtp::class)->last()->code])
            ->assertRedirect(route('dashboard'))->assertCookieMissing('ea_staff');
    }

    public function test_customer_login_never_receives_the_staff_pass(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->postJson('/api/v1/customer/auth/login', ['email' => $customer->email, 'password' => 'password'])->assertAccepted()->assertCookieMissing('ea_staff');
        $code = Notification::sent($customer, RiderEmailOtp::class)->last()->code;

        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $code])->assertSuccessful()->assertCookieMissing('ea_staff');
    }
}
