<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerDeviceVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->unverified()->create(['role' => UserRole::Customer]);
    }

    private function login(User $user, ?string $device = null, bool $remember = false): TestResponse
    {
        $request = $device === null ? $this : $this->withCookie('ea_trusted_customer', $device);

        return $request->postJson('/api/v1/customer/auth/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember]);
    }

    private function authorizeDevice(User $user): string
    {
        Notification::fake();
        $this->login($user)->assertAccepted()->assertJsonPath('otp_required', true);
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $response = $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $code])->assertOk()->assertJsonPath('user.slug', $user->slug);
        $cookie = $response->getCookie('ea_trusted_customer');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->postJson('/api/v1/customer/auth/logout')->assertOk();
        app('cookie')->unqueue('ea_trusted_customer');
        $this->assertGuest('customer');
        $this->travel(61)->seconds();

        return $cookie->getValue();
    }

    public function test_new_account_must_confirm_the_otp_before_reaching_the_portal(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/customer/auth/register', ['privacy_acknowledged' => true, 'beta_terms_accepted' => true, 'name' => 'Rita Rossi', 'email' => 'rita@example.test', 'password' => 'PasswordSicura123', 'password_confirmation' => 'PasswordSicura123', 'sender_type' => 'private'])->assertAccepted();
        $this->assertGuest('customer');
        $user = User::where('email', 'rita@example.test')->sole();

        $this->postJson('/api/v1/customer/auth/login', ['email' => 'rita@example.test', 'password' => 'PasswordSicura123'])
            ->assertAccepted()->assertJsonPath('otp_required', true)->assertJsonPath('email', 'r•••@example.test')->assertJsonMissingPath('user');

        $this->assertGuest('customer');
        $this->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/customer/auth/otp')->assertOk()->assertJson(['pending' => true, 'email' => 'r•••@example.test']);
        $notification = Notification::sent($user, RiderEmailOtp::class)->sole();
        $this->assertSame('Codice di accesso al portale · EA-Express', $notification->toMail($user)->subject);

        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $notification->code])->assertOk()->assertJsonPath('user.slug', $user->slug);

        $this->assertAuthenticatedAs($user, 'customer');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->getJson('/api/v1/customer/auth/me')->assertOk();
        $this->getJson('/api/v1/customer/auth/otp')->assertJson(['pending' => false, 'email' => null]);
        $this->assertDatabaseMissing('economic_audits', ['user_id' => $user->id]);
        $this->assertDatabaseCount('trusted_rider_devices', 1);
    }

    public function test_verified_device_logs_in_directly_and_rotates_its_credential(): void
    {
        $this->freezeTime();
        $user = $this->customer();
        $credential = $this->authorizeDevice($user);
        [$slug, $secret] = explode('.', $credential);
        $this->assertDatabaseHas('trusted_rider_devices', ['slug' => $slug, 'user_id' => $user->id, 'token_hash' => hash('sha256', $secret), 'revoked_at' => null]);
        Notification::fake();

        $response = $this->login($user, $credential)->assertOk()->assertJsonPath('user.slug', $user->slug);

        Notification::assertNothingSent();
        $this->assertAuthenticatedAs($user, 'customer');
        $this->assertNotSame($credential, $response->getCookie('ea_trusted_customer')->getValue());
        $this->assertDatabaseCount('trusted_rider_devices', 1);
        $this->getJson('/api/v1/customer/auth/me')->assertOk();
    }

    public static function untrustedDevices(): array
    {
        return ['other device' => ['absent'], 'altered cookie' => ['altered'], 'expired' => ['expired'], 'revoked' => ['revoked'], 'password changed' => ['password'], 'rider cookie' => ['rider']];
    }

    #[DataProvider('untrustedDevices')]
    public function test_unknown_or_invalid_device_requires_a_new_otp(string $condition): void
    {
        $user = $this->customer();
        $credential = $this->authorizeDevice($user);
        if ($condition === 'absent') {
            $credential = null;
        } elseif ($condition === 'altered') {
            $credential = substr($credential, 0, -1).(str_ends_with($credential, 'a') ? 'b' : 'a');
        } elseif ($condition === 'expired') {
            DB::table('trusted_rider_devices')->update(['expires_at' => now()->subSecond()]);
        } elseif ($condition === 'revoked') {
            DB::table('trusted_rider_devices')->update(['revoked_at' => now()]);
        } elseif ($condition === 'password') {
            $user->forceFill(['password' => 'password'])->save();
        } elseif ($condition === 'rider') {
            $this->withCookie('ea_trusted_rider', $credential);
            $credential = null;
        }
        Notification::fake();

        $this->login($user, $credential)->assertAccepted()->assertJsonPath('otp_required', true);

        Notification::assertSentTo($user, RiderEmailOtp::class);
        $this->assertGuest('customer');
        $this->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
    }

    public function test_wrong_or_stale_codes_never_authenticate_the_customer(): void
    {
        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/customer/auth/otp/send')->assertUnprocessable()->assertJsonValidationErrors('code');

        Notification::fake();
        $user = $this->customer();
        $this->login($user)->assertAccepted();
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertGuest('customer');
        $this->assertDatabaseCount('trusted_rider_devices', 0);

        $this->travel(31)->minutes();
        $this->getJson('/api/v1/customer/auth/otp')->assertJson(['pending' => false]);
        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $code])->assertUnprocessable();
        $this->assertGuest('customer');
    }

    public function test_code_can_be_resent_and_only_the_latest_one_is_accepted(): void
    {
        Notification::fake();
        $user = $this->customer();
        $this->login($user)->assertAccepted();
        $first = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $this->postJson('/api/v1/customer/auth/otp/send')->assertTooManyRequests();

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/customer/auth/otp/send')->assertOk();
        $second = Notification::sent($user, RiderEmailOtp::class)->last()->code;

        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $first])->assertUnprocessable();
        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $second])->assertOk();
        $this->assertAuthenticatedAs($user, 'customer');
    }

    public function test_repeated_login_during_the_resend_interval_keeps_the_otp_step_and_reports_the_wait(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = $this->customer();
        $this->login($user)->assertAccepted();

        $this->login($user)->assertAccepted()->assertJsonPath('otp_required', true)->assertJsonPath('retry_after', 60);

        Notification::assertSentToTimes($user, RiderEmailOtp::class, 1);
        $this->assertGuest('customer');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/customer/auth/otp/send')->assertOk();
        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => Notification::sent($user, RiderEmailOtp::class)->last()->code])->assertOk();
    }

    public function test_remember_preference_is_applied_only_after_the_otp(): void
    {
        Notification::fake();
        $user = $this->customer();
        $recaller = Auth::guard('customer')->getRecallerName();

        $this->login($user, remember: true)->assertAccepted()->assertCookieMissing($recaller);
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;

        $this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $code])->assertOk()->assertCookie($recaller);
    }

    public function test_staff_otp_session_cannot_be_completed_through_the_customer_endpoint(): void
    {
        Notification::fake();
        $rider = User::factory()->create();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($rider, RiderEmailOtp::class)->last()->code;

        $this->assertContains($this->postJson('/api/v1/customer/auth/otp/verify', ['code' => $code])->status(), [403, 422]);

        $this->assertGuest('customer');
        $this->assertNotNull($rider->fresh()->email_otp_hash);
        $this->getJson('/dashboard')->assertForbidden();
    }
}
