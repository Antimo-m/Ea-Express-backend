<?php

namespace Tests\Feature;

use App\Actions\ChangePassword;
use App\Actions\UpdateProfile;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\StaffAuthenticationState;
use App\Support\StaffAuthentication;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffAuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function loginPending(bool $remember = false): User
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertRedirect(route('email-otp.notice'));

        return $user;
    }

    private function code(User $user): string
    {
        return Notification::sent($user, RiderEmailOtp::class)->last()->code;
    }

    public function test_real_primary_login_cannot_change_password_or_spoof_verification(): void
    {
        $user = $this->loginPending();
        $original = $user->fresh()->password;
        $this->assertSame(StaffAuthenticationState::PrimaryAuthenticated->value, session('staff_authentication.state'));
        $this->putJson('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'verified' => true, 'mfa_complete' => 1, 'phone_verified' => true])->assertForbidden();
        $this->assertSame($original, $user->fresh()->password);
        $this->get('/profile')->assertRedirect(route('email-otp.notice'));
        $this->get('/confirm-password')->assertRedirect(route('email-otp.notice'));
        $this->postJson('/confirm-password', ['password' => 'password'])->assertForbidden();
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_partial_sessions_cannot_call_alternate_sensitive_endpoints(): void
    {
        $user = $this->loginPending();
        foreach ([['PATCH', '/profile'], ['DELETE', '/profile'], ['POST', '/settings/users'], ['PATCH', '/settings/users/'.$user->id], ['PATCH', '/settings'], ['POST', '/balance/123/payment'], ['POST', '/movements'], ['POST', '/expenses'], ['PATCH', '/settings/accounting'], ['GET', '/reports'], ['GET', '/economic-audits'], ['GET', '/realtime/configuration'], ['POST', '/realtime/auth'], ['PATCH', '/orders/123'], ['POST', '/orders'], ['PATCH', '/recipient-incidents/123']] as [$method, $uri]) {
            $response = $this->json($method, $uri, ['name' => 'Attacker', 'current_password' => 'password']);
            $this->assertSame(403, $response->status(), $method.' '.$uri);
        }
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_movements', 0);
    }

    public function test_customer_api_cannot_create_an_alternative_staff_session(): void
    {
        $user = $this->loginPending();
        $original = $user->fresh()->password;
        $this->putJson('/api/v1/customer/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
        $this->put('/api/v1/customer/password', ['current_password' => 'password'])->assertForbidden()->assertJson(['message' => 'Completa prima la verifica di accesso.']);
        $this->postJson('/api/v1/customer/auth/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $this->assertGuest('customer');
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_global_guard_protects_future_routes_without_explicit_verification_middleware(): void
    {
        Route::post('/security-test-sensitive', fn () => response()->json(['privileged' => true]))->middleware(['web', 'auth']);
        $this->loginPending();
        $this->postJson('/security-test-sensitive')->assertForbidden();
    }

    public function test_correct_otp_rotates_session_and_csrf_and_allows_password_change(): void
    {
        $user = $this->loginPending();
        $sessionId = session()->getId();
        $csrf = session()->token();
        $this->post('/verify-email-otp/confirm', ['code' => $this->code($user)])->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertSame(StaffAuthenticationState::FullyAuthenticated->value, session('staff_authentication.state'));
        $this->assertNull(session('email_otp_challenge'));
        $this->get('/profile')->assertOk();
        $this->put('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->get('/profile')->assertOk();
        $this->getJson('/settings/users')->assertForbidden();
    }

    public function test_wrong_otp_does_not_elevate_the_session(): void
    {
        $user = $this->loginPending();
        $wrong = $this->code($user) === '111111' ? '222222' : '111111';
        $this->postJson('/verify-email-otp/confirm', ['code' => $wrong])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(1, $user->fresh()->email_otp_attempts);
        $this->getJson('/profile')->assertForbidden();
    }

    public function test_expired_otp_is_invalidated_without_privilege_escalation(): void
    {
        $user = $this->loginPending();
        $code = $this->code($user);
        $this->travel(16)->minutes();
        $this->postJson('/verify-email-otp/confirm', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->getJson('/profile')->assertForbidden();
    }

    public function test_consumed_otp_and_copied_challenge_cannot_be_replayed(): void
    {
        $user = $this->loginPending();
        $code = $this->code($user);
        $challenge = session('email_otp_challenge');
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $verifiedAt = $user->fresh()->email_verified_at->toISOString();
        $this->withSession(['email_otp_challenge' => $challenge])->postJson('/verify-email-otp/confirm', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame($verifiedAt, $user->fresh()->email_verified_at->toISOString());
        $this->assertNull($user->fresh()->email_otp_hash);
    }

    public function test_user_a_challenge_cannot_verify_user_b(): void
    {
        $owner = $this->loginPending();
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->postJson('/verify-email-otp/confirm', ['code' => $this->code($owner), 'user_id' => $owner->id])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
        $this->assertNotNull($owner->fresh()->email_otp_hash);
    }

    public function test_same_user_challenge_is_rejected_in_another_session(): void
    {
        $user = $this->loginPending();
        $originalHash = $user->fresh()->email_otp_hash;
        session()->migrate(true);
        request()->setLaravelSession(app('session.store'));
        app(StaffAuthentication::class)->begin(request(), $user->fresh());
        $this->postJson('/verify-email-otp/confirm', ['code' => $this->code($user)])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($originalHash, $user->fresh()->email_otp_hash);
    }

    public function test_resend_invalidates_previous_challenge_even_with_restored_session_data(): void
    {
        $user = $this->loginPending();
        $challenge = session('email_otp_challenge');
        $oldCode = $this->code($user);
        $this->travel(61)->seconds();
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $newChallenge = session('email_otp_challenge');
        $this->assertNotSame($challenge['challenge_id'], $newChallenge['challenge_id']);
        $this->withSession(['email_otp_challenge' => $challenge])->postJson('/verify-email-otp/confirm', ['code' => $oldCode])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->withSession(['email_otp_challenge' => $newChallenge])->post('/verify-email-otp/confirm', ['code' => $this->code($user)])->assertSessionHasNoErrors();
    }

    public function test_logout_destroys_partial_state_and_invalidates_otp(): void
    {
        $user = $this->loginPending();
        $code = $this->code($user);
        $id = session()->getId();
        $csrf = session()->token();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertNull(session('staff_authentication'));
        $this->assertNull(session('email_otp_challenge'));
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $this->postJson('/verify-email-otp/confirm', ['code' => $code])->assertUnprocessable();
    }

    public function test_account_verification_elsewhere_cannot_elevate_a_pending_session(): void
    {
        $user = $this->loginPending();
        $user->forceFill(['email_verified_at' => now(), 'email_otp_hash' => null])->save();
        Auth::guard('web')->setUser($user->fresh());
        $this->putJson('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->get('/verify-email-otp')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_reset_does_not_authenticate_or_satisfy_pending_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = Password::broker('users')->createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password'])->assertRedirect(route('email-otp.notice'));
        $this->getJson('/profile')->assertForbidden();
    }

    public function test_remember_cookie_is_emitted_only_after_required_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $cookieName = Auth::guard('web')->getRecallerName();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertCookieMissing($cookieName);
        $this->post('/verify-email-otp/confirm', ['code' => $this->code($user)])->assertCookie($cookieName);
        $this->assertNotNull($user->fresh()->remember_token);
        $this->assertSame(StaffAuthenticationState::FullyAuthenticated->value, session('staff_authentication.state'));
    }

    public function test_admin_and_verified_rider_keep_the_existing_login_policy(): void
    {
        Notification::fake();
        foreach ([UserRole::Admin, UserRole::Rider] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
            $this->assertSame(StaffAuthenticationState::FullyAuthenticated->value, session('staff_authentication.state'));
            $this->get('/profile')->assertOk();
            $this->post('/logout');
        }
        Notification::assertNothingSent();
    }

    public function test_missing_or_expired_server_proof_requires_a_new_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['staff_authentication' => null])->getJson('/profile')->assertForbidden();
        $this->assertGuest();
        $pending = $this->loginPending();
        $this->travel(31)->minutes();
        $this->getJson('/profile')->assertForbidden();
        $this->assertGuest();
        $this->assertNull($pending->fresh()->email_otp_hash);
    }

    public function test_sensitive_actions_reject_partial_authentication_without_route_middleware(): void
    {
        $user = $this->loginPending();
        foreach ([fn () => app(ChangePassword::class)->handle($user, 'password', 'new-password'), fn () => app(UpdateProfile::class)->handle($user, ['name' => 'Attacker'])] as $operation) {
            try {
                $operation();
                $this->fail('A partial session reached a sensitive action.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_password_change_revokes_other_sessions_reset_tokens_and_pending_otp(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/profile')->assertOk();
        $oldRemember = $user->fresh()->remember_token;
        $resetToken = Password::broker('users')->createToken($user);
        $user->forceFill(['email_otp_hash' => 'pending-challenge', 'email_otp_expires_at' => now()->addMinutes(15)])->save();
        DB::table('sessions')->insert(['id' => 'another-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        config(['session.driver' => 'database']);
        app(ChangePassword::class)->handle($user, 'password', 'new-password');
        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);
        $this->assertFalse(Password::broker('users')->tokenExists($user->fresh(), $resetToken));
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
    }

    public function test_security_events_do_not_contain_credentials_or_otp(): void
    {
        Log::spy();
        $user = $this->loginPending();
        $code = $this->code($user);
        $this->getJson('/profile')->assertForbidden();
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasNoErrors();
        foreach (['otp_challenge_created', 'otp_sent', 'partial_auth_access_denied', 'otp_verified'] as $event) {
            Log::shouldHaveReceived('notice')->with('security.'.$event, \Mockery::on(function (array $context) use ($code): bool {
                return array_keys($context) === ['user_id', 'ip', 'route', 'request_id']
                    && ! in_array($code, $context, true)
                    && ! array_key_exists('password', $context);
            }))->atLeast()->once();
        }
    }
}
