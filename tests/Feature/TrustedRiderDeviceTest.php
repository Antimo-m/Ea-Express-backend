<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedRiderDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function authorizeDevice(User $user): string
    {
        Notification::fake();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $response = $this->post('/verify-email-otp/confirm', ['code' => $code])->assertRedirect('/dashboard');
        $cookie = $response->getCookie('ea_trusted_rider');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->get('/dashboard')->assertOk();
        $this->post('/logout')->assertRedirect('/');
        app('cookie')->unqueue('ea_trusted_rider');
        app('cookie')->unqueue('ea_staff_device');
        $this->assertGuest();

        return $cookie->getValue();
    }

    public function test_otp_authorizes_a_hashed_device_and_next_password_login_rotates_it_without_otp(): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $credential = $this->authorizeDevice($user);
        [$slug, $secret] = explode('.', $credential);
        $this->assertDatabaseHas('trusted_rider_devices', ['slug' => $slug, 'user_id' => $user->id, 'token_hash' => hash('sha256', $secret), 'revoked_at' => null]);
        $this->assertSame(now()->addDays(30)->format('Y-m-d H:i:s'), DB::table('trusted_rider_devices')->value('expires_at'));
        Notification::fake();

        $response = $this->withCookie('ea_trusted_rider', $credential)->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect('/dashboard')->assertCookie('ea_trusted_rider');
        $rotated = $response->getCookie('ea_trusted_rider')->getValue();
        $this->assertNotSame($credential, $rotated);
        $this->assertDatabaseCount('trusted_rider_devices', 1);
        Notification::assertNothingSent();
        $this->get('/dashboard')->assertOk();
        $this->post('/logout');
        $this->travel(61)->seconds();
        $this->withCookie('ea_trusted_rider', $credential)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
    }

    public static function invalidDevices(): array
    {
        return ['absent' => ['absent'], 'altered' => ['altered'], 'expired' => ['expired'], 'revoked' => ['revoked'], 'password changed' => ['password'], 'email reset' => ['email'], 'risky login' => ['risk']];
    }

    #[DataProvider('invalidDevices')]
    public function test_invalid_or_risky_device_requires_a_new_otp(string $condition): void
    {
        $user = User::factory()->create();
        $credential = $this->authorizeDevice($user);
        if ($condition === 'absent') {
            $credential = '';
        } elseif ($condition === 'altered') {
            $credential = substr($credential, 0, -1).(str_ends_with($credential, 'a') ? 'b' : 'a');
        } elseif ($condition === 'expired') {
            DB::table('trusted_rider_devices')->update(['expires_at' => now()->subSecond()]);
        } elseif ($condition === 'revoked') {
            DB::table('trusted_rider_devices')->update(['revoked_at' => now()]);
        } elseif ($condition === 'password') {
            $user->forceFill(['password' => 'different-password'])->save();
        } elseif ($condition === 'email') {
            $admin = User::factory()->create(['role' => UserRole::Admin]);
            $this->actingAs($admin)->patch(route('users.update', $user), ['action' => 'reset-email'])->assertRedirect();
            $this->post('/logout');
        } elseif ($condition === 'risk') {
            config(['auth_security.monitor.distinct_ips' => 1]);
        }
        $this->travel(61)->seconds();
        Notification::fake();

        $this->withCookie('ea_trusted_rider', $credential)->post('/login', ['email' => $user->email, 'password' => $condition === 'password' ? 'different-password' : 'password'])->assertRedirect(route('email-otp.notice'));

        Notification::assertSentTo($user, RiderEmailOtp::class);
        $this->getJson('/dashboard')->assertForbidden();
    }

    public function test_another_rider_cannot_use_the_device_and_password_is_always_required(): void
    {
        $user = User::factory()->create();
        $credential = $this->authorizeDevice($user);
        $other = User::factory()->create();
        $this->withCookie('ea_trusted_rider', $credential)->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertGuest();
        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $this->getJson('/dashboard')->assertForbidden();
    }

    public function test_admin_always_requires_otp_even_with_a_rider_cookie(): void
    {
        $rider = User::factory()->create();
        $credential = $this->authorizeDevice($rider);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->withCookie('ea_trusted_rider', $credential)->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($admin, RiderEmailOtp::class)->last()->code;
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertCookieMissing('ea_trusted_rider');
        $this->assertDatabaseCount('trusted_rider_devices', 1);
    }

    public function test_admin_revocation_preserves_orders_and_invalidates_devices_and_existing_session(): void
    {
        $rider = User::factory()->create();
        $credential = $this->authorizeDevice($rider);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'revoke-devices'])->assertRedirect();
        $this->assertNotNull(DB::table('trusted_rider_devices')->value('revoked_at'));
        $this->assertDatabaseHas('economic_audits', ['action' => 'user.revoke-devices', 'user_id' => $admin->id]);
        $this->post('/logout');
        $this->travel(61)->seconds();
        $this->withCookie('ea_trusted_rider', $credential)->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
    }

    public function test_password_change_revokes_all_devices(): void
    {
        $rider = User::factory()->create();
        $this->authorizeDevice($rider);
        $this->actingAs($rider);

        $this->put('/password', ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('trusted_rider_devices')->value('revoked_at'));
    }

    public function test_secure_cookie_is_used_when_https_sessions_are_required(): void
    {
        config(['session.secure' => true]);
        $user = User::factory()->create();
        Notification::fake();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $response = $this->post('/verify-email-otp/confirm', ['code' => $code]);
        $this->assertTrue($response->getCookie('ea_trusted_rider')->isSecure());
    }

    public function test_admin_revocation_rejects_the_existing_staff_session_even_without_database_sessions(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($rider)->get('/dashboard')->assertOk();
        $previousProof = session('staff_authentication');
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'revoke-devices'])->assertRedirect();
        Auth::guard('web')->setUser($rider->fresh());
        $this->withSession(['staff_authentication' => $previousProof])->getJson('/dashboard')->assertForbidden();
    }
}
