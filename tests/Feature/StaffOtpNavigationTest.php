<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderActivationLink;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffOtpNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool}> */
    public static function rememberOptions(): array
    {
        return ['session only' => [false], 'remembered session' => [true]];
    }

    #[DataProvider('rememberOptions')]
    public function test_first_rider_login_survives_refresh_and_reaches_dashboard_with_a_fresh_guard(bool $remember): void
    {
        Notification::fake([RiderEmailOtp::class]);
        $rider = User::factory()->unverified()->create();
        $this->withSession(['url.intended' => '/login']);

        $this->post('/login', ['email' => $rider->email, 'password' => 'password', 'remember' => $remember])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($rider, RiderEmailOtp::class)->sole()->code;
        Auth::forgetGuards();
        $this->get(route('email-otp.notice'))->assertOk();
        Auth::forgetGuards();
        $this->get(route('email-otp.notice'))->assertOk();
        $this->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        $sessionId = session()->getId();

        Auth::forgetGuards();
        $this->post(route('email-otp.verify'), ['code' => $code])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotNull($rider->fresh()->email_verified_at);
        $this->assertNull($rider->fresh()->email_otp_hash);
        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.rider');
        $this->assertAuthenticatedAs($rider);

        $this->post(route('logout'))->assertRedirect('/');
        Auth::forgetGuards();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($rider, RiderEmailOtp::class)->last()->code;
        $this->post(route('email-otp.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.rider');
        Notification::assertSentToTimes($rider, RiderEmailOtp::class, 2);
    }

    public function test_admin_access_requires_otp_and_survives_a_fresh_guard(): void
    {
        Notification::fake([RiderEmailOtp::class]);
        $admin = User::factory()->unverified()->create(['role' => UserRole::Admin]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($admin, RiderEmailOtp::class)->last()->code;
        $this->post(route('email-otp.verify'), ['code' => $code])->assertRedirect(route('dashboard'));

        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.index');
        Notification::assertSentToTimes($admin, RiderEmailOtp::class, 1);
    }

    public function test_rider_created_by_an_admin_reaches_dashboard_on_the_first_otp_confirmation(): void
    {
        Notification::fake([RiderEmailOtp::class, RiderActivationLink::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $password = 'FirstLoginPassword123';
        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Nuovo Rider', 'email' => 'new-rider@example.test'])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $rider = User::where('email', 'new-rider@example.test')->sole();
        $this->assertNull($rider->email_verified_at);
        $this->post(route('logout'));
        Auth::forgetGuards();
        $this->activateRider($rider, $password);

        $this->post('/login', ['email' => $rider->email, 'password' => $password])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($rider, RiderEmailOtp::class)->sole()->code;
        Auth::forgetGuards();
        $this->post(route('email-otp.verify'), ['code' => $code])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.rider');
        $this->assertAuthenticatedAs($rider);
    }
}
