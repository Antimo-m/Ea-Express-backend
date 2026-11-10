<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustAuthenticationProxies;
use App\Jobs\SendAuthSecurityAlert;
use App\Models\User;
use App\Notifications\AuthenticationAlert;
use App\Notifications\RiderEmailOtp;
use App\StaffAuthenticationState;
use App\Support\AuthProtection;
use App\Support\AuthSecurityMonitor;
use App\Support\AuthSecurityStore;
use App\Support\AuthUserProvider;
use App\Support\PasswordPolicy;
use App\Support\StaffDeviceProof;
use App\UserRole;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function wrong(string $email = 'unknown@example.test', string $path = '/login'): TestResponse
    {
        return $this->postJson($path, ['email' => $email, 'password' => 'incorrect-password']);
    }

    public function test_first_two_errors_allow_another_attempt_and_third_enforces_ten_seconds(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 3; $i++) {
            $this->wrong()->assertUnprocessable()->assertJsonValidationErrors(['email' => AuthProtection::INVALID_CREDENTIALS]);
        }
        $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', '10')->assertJsonPath('retry_after', 10);
        $this->travel(10)->seconds();
        $this->wrong()->assertUnprocessable();
        $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', '30');
        $this->travel(30)->seconds();
        $this->wrong()->assertUnprocessable();
        $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->travel(60)->seconds();
        $this->wrong()->assertUnprocessable();
        $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', '300');
    }

    public function test_tenth_failure_suspends_for_fifteen_minutes_and_denied_requests_do_not_extend_it(): void
    {
        $this->freezeTime();
        foreach ([0, 0, 10, 30, 60, 300, 300, 300, 300, 900] as $delay) {
            $this->wrong()->assertUnprocessable();
            if ($delay) {
                $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', (string) $delay);
                $this->travel($delay)->seconds();
            }
        }
        $this->wrong()->assertUnprocessable();
        $this->wrong()->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_account_window_expires_and_blocked_requests_do_not_refresh_failures(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 3; $i++) {
            $this->wrong()->assertUnprocessable();
        }
        $this->travel(5)->seconds();
        $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', '5');
        $this->travel(3600)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $this->wrong()->assertUnprocessable();
        }
        $this->wrong()->assertTooManyRequests()->assertHeader('Retry-After', '10');
    }

    public function test_changing_ip_case_and_guard_does_not_reset_account_limit(): void
    {
        $this->freezeTime();
        foreach ([1, 2, 3] as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->wrong('Target@example.test')->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->wrong('target@EXAMPLE.test', '/api/v1/customer/auth/login')->assertTooManyRequests()->assertHeader('Retry-After', '10');
        $this->assertSame(3, count(Cache::get(AuthSecurityStore::key('account', 'target@example.test'))['failures']));
        $this->assertStringNotContainsString('target', AuthSecurityStore::key('account', 'target@example.test'));
    }

    public function test_changing_account_does_not_bypass_ip_limit_and_ip_window_expires(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 20; $i++) {
            $this->wrong('target'.$i.'@example.test')->assertUnprocessable();
        }
        $this->wrong('another@example.test', '/api/v1/customer/auth/login')->assertTooManyRequests()->assertHeader('Retry-After', '600');
        $this->travel(600)->seconds();
        $this->wrong('another@example.test')->assertUnprocessable();
    }

    public function test_missing_inactive_and_wrong_role_accounts_have_identical_errors(): void
    {
        User::factory()->create(['email' => 'inactive@example.test', 'is_active' => false]);
        User::factory()->create(['email' => 'customer@example.test', 'role' => UserRole::Customer]);
        User::factory()->create(['email' => 'rider@example.test']);
        $expected = $this->wrong('absent@example.test')->assertUnprocessable()->json();
        foreach (['inactive@example.test', 'customer@example.test', 'rider@example.test'] as $email) {
            $this->wrong($email)->assertUnprocessable()->assertExactJson($expected);
        }
    }

    private function useDefaultDummyPasswordHash(): void
    {
        $environmentHash = $_ENV['AUTH_DUMMY_PASSWORD_HASH'] ?? null;
        $serverHash = $_SERVER['AUTH_DUMMY_PASSWORD_HASH'] ?? null;
        $processHash = getenv('AUTH_DUMMY_PASSWORD_HASH');
        unset($_ENV['AUTH_DUMMY_PASSWORD_HASH'], $_SERVER['AUTH_DUMMY_PASSWORD_HASH']);
        putenv('AUTH_DUMMY_PASSWORD_HASH');

        try {
            $configuration = require config_path('auth_security.php');
        } finally {
            if ($environmentHash !== null) {
                $_ENV['AUTH_DUMMY_PASSWORD_HASH'] = $environmentHash;
            }
            if ($serverHash !== null) {
                $_SERVER['AUTH_DUMMY_PASSWORD_HASH'] = $serverHash;
            }
            if ($processHash !== false) {
                putenv('AUTH_DUMMY_PASSWORD_HASH='.$processHash);
            }
        }

        config(['auth_security.dummy_password_hash' => $configuration['dummy_password_hash']]);
    }

    public function test_default_password_hash_allows_generic_rejection_of_missing_and_inactive_accounts(): void
    {
        $this->useDefaultDummyPasswordHash();
        $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => AuthProtection::INVALID_CREDENTIALS]);
        $this->assertGuest();

        $this->wrong()->assertUnprocessable()->assertJsonValidationErrors(['email' => AuthProtection::INVALID_CREDENTIALS]);
        $this->assertGuest();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidLoginCredentials(): array
    {
        return [
            'email typo with correct password' => ['acount@example.test', 'password'],
            'correct email with wrong password' => ['account@example.test', 'incorrect-password'],
            'both credentials wrong' => ['acount@example.test', 'incorrect-password'],
        ];
    }

    #[DataProvider('invalidLoginCredentials')]
    public function test_invalid_rider_credentials_display_an_error_on_the_login_page(string $email, string $password): void
    {
        $this->useDefaultDummyPasswordHash();
        User::factory()->create(['email' => 'account@example.test', 'role' => UserRole::Rider]);
        Notification::fake();

        $this->from('/login')->post('/login', ['email' => $email, 'password' => $password])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => AuthProtection::INVALID_CREDENTIALS])
            ->assertSessionMissing('_old_input.password');

        $this->get('/login')
            ->assertOk()
            ->assertSee(AuthProtection::INVALID_CREDENTIALS)
            ->assertSee('id="email-error"', escape: false)
            ->assertSee('value="'.$email.'"', escape: false);
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    #[DataProvider('invalidLoginCredentials')]
    public function test_invalid_customer_credentials_return_a_422_validation_error(string $email, string $password): void
    {
        $this->useDefaultDummyPasswordHash();
        User::factory()->create(['email' => 'account@example.test', 'role' => UserRole::Customer]);
        Notification::fake();

        $this->postJson('/api/v1/customer/auth/login', ['email' => $email, 'password' => $password])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => AuthProtection::INVALID_CREDENTIALS]);

        $this->assertGuest('customer');
        Notification::assertNothingSent();
    }

    public function test_counter_store_failure_returns_503_without_authenticating(): void
    {
        $user = User::factory()->create();
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('redis://private-secret'));
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(503)->assertHeader('Retry-After', '30')->assertDontSee('private-secret');
        $this->assertGuest();
    }

    public function test_busy_account_lock_rejects_concurrent_attempt_without_incrementing_failures(): void
    {
        $key = AuthSecurityStore::key('account', 'target@example.test');
        $lock = Cache::lock($key.':lock', 30);
        $this->assertTrue($lock->get());
        try {
            $this->wrong('target@example.test')->assertTooManyRequests()->assertHeader('Retry-After', '1');
            $this->assertNull(Cache::get($key));
        } finally {
            $lock->release();
        }
        $this->wrong('target@example.test')->assertUnprocessable();
        $this->assertCount(1, Cache::get($key)['failures']);
    }

    public static function staffRoles(): array
    {
        return ['admin' => [UserRole::Admin, 'dashboard.index'], 'rider' => [UserRole::Rider, 'dashboard.rider']];
    }

    #[DataProvider('staffRoles')]
    public function test_each_staff_login_requires_otp_and_completes_without_second_password(UserRole $role, string $dashboard): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => $role]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $this->assertSame(StaffAuthenticationState::PrimaryAuthenticated->value, session('staff_authentication.state'));
        $this->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $this->assertSame(300, $user->fresh()->email_otp_expires_at->timestamp - $user->fresh()->email_otp_sent_at->timestamp);
        $id = session()->getId();
        $csrf = session()->token();
        $this->post('/verify-email-otp/confirm', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertNull(session('email_otp_challenge'));
        $this->assertNull(session('staff_authentication.remember'));
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->app['auth']->forgetGuards();
        $this->get('/dashboard')->assertOk()->assertViewIs($dashboard);
        $this->postJson('/verify-email-otp/confirm', ['code' => $code])->assertUnprocessable();
        $this->post('/logout');
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        Notification::assertSentToTimes($user, RiderEmailOtp::class, 2);
    }

    private function pending(): User
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));

        return $user;
    }

    public function test_otp_resends_preserve_errors_invalidate_old_code_and_allow_only_three_resends(): void
    {
        $this->freezeTime();
        $user = $this->pending();
        $old = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $this->postJson('/verify-email-otp/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/verify-email-otp/send')->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->travel(60)->seconds();
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $this->assertSame(1, $user->fresh()->email_otp_attempts);
        $this->postJson('/verify-email-otp/confirm', ['code' => $old])->assertUnprocessable();
        for ($i = 0; $i < 2; $i++) {
            $this->travel(60)->seconds();
            $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        }
        $this->travel(60)->seconds();
        $this->postJson('/verify-email-otp/send')->assertTooManyRequests();
        $this->assertSame(2, $user->fresh()->email_otp_attempts);
        Notification::assertSentToTimes($user, RiderEmailOtp::class, 4);
    }

    public function test_five_otp_errors_exhaust_procedure_and_resend_cannot_reset_it(): void
    {
        $this->freezeTime();
        $user = $this->pending();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/verify-email-otp/confirm', ['code' => '000000'])->assertUnprocessable();
        }
        $this->assertSame(5, $user->fresh()->email_otp_attempts);
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->travel(61)->seconds();
        $this->postJson('/verify-email-otp/send')->assertTooManyRequests()->assertHeader('Retry-After', '839');
        $this->travel(839)->seconds();
        $this->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $this->assertSame(0, $user->fresh()->email_otp_attempts);
        Notification::assertSentToTimes($user, RiderEmailOtp::class, 2);
    }

    public function test_otp_expires_at_five_minutes_and_copied_session_cannot_revive_it(): void
    {
        $this->freezeTime();
        $user = $this->pending();
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $challenge = session('email_otp_challenge');
        $this->travel(300)->seconds();
        $this->withSession(['email_otp_challenge' => $challenge])->postJson('/verify-email-otp/confirm', ['code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->get('/dashboard')->assertRedirect(route('email-otp.notice'));
    }

    public function test_otp_account_aggregate_survives_new_sessions_and_ip_changes(): void
    {
        config(['auth_security.otp.account_attempts' => 2]);
        $user = $this->pending();
        $this->postJson('/verify-email-otp/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->post('/logout');
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $this->postJson('/verify-email-otp/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/verify-email-otp/confirm', ['code' => Notification::sent($user, RiderEmailOtp::class)->last()->code])->assertTooManyRequests();
        $this->assertSame(StaffAuthenticationState::PrimaryAuthenticated->value, session('staff_authentication.state'));
    }

    public function test_global_spike_only_alerts_and_admin_notifications_are_cooled_down(): void
    {
        Queue::fake([SendAuthSecurityAlert::class]);
        config(['auth_security.monitor.global_attempts' => 3]);
        for ($i = 0; $i < 8; $i++) {
            $this->wrong('target'.$i.'@example.test')->assertUnprocessable();
        }
        Queue::assertPushed(SendAuthSecurityAlert::class, 1);
        Queue::assertPushedOn('security', SendAuthSecurityAlert::class);
        $this->travel(901)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $this->wrong('other'.$i.'@example.test')->assertUnprocessable();
        }
        Queue::assertPushed(SendAuthSecurityAlert::class, 2);
    }

    public function test_alert_worker_only_notifies_active_admins_and_contains_no_account_secrets(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $disabled = User::factory()->create(['is_active' => false]);
        $rider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        (new SendAuthSecurityAlert('account_targeted', 'request-correlation'))->handle();
        Notification::assertSentTo($admin, AuthenticationAlert::class);
        Notification::assertNotSentTo([$disabled, $rider, $customer], AuthenticationAlert::class);
        $admin->forceFill(['is_active' => false])->saveQuietly();
        Notification::fake();
        (new SendAuthSecurityAlert('account_targeted', 'disabled-admin'))->handle();
        Notification::assertNothingSent();
        $notification = new AuthenticationAlert('account_targeted', 'request-correlation');
        $this->assertSame([], $notification->via($rider));
        $this->assertStringNotContainsString($rider->email, $notification->toMail($admin)->render());
    }

    public function test_turnstile_is_enforced_by_api_and_checks_action_hostname_and_replay(): void
    {
        Http::preventStrayRequests();
        $this->botConfiguration();
        config(['auth_security.bot.always' => true]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $path = '/api/v1/customer/auth/login';
        $data = ['email' => $customer->email, 'password' => 'password'];
        $this->postJson($path, $data)->assertUnprocessable()->assertJsonPath('bot_challenge.site_key', 'public-site-key');
        $this->assertGuest('customer');
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
            ->push(['success' => true, 'hostname' => 'attacker.test', 'action' => 'login'])
            ->push(['success' => true, 'hostname' => 'portal.example.test', 'action' => 'other'])
            ->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']])
            ->push(['success' => true, 'hostname' => 'portal.example.test', 'action' => 'login'])]);
        $data['cf-turnstile-response'] = 'opaque-token';
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($path, $data)->assertUnprocessable();
            $this->assertGuest('customer');
        }
        $this->postJson($path, $data)->assertOk()->assertJsonPath('user.slug', $customer->slug);
        Http::assertSentCount(4);
    }

    private function botConfiguration(): void
    {
        config(['auth_security.bot.enabled' => true, 'auth_security.bot.site_key' => 'public-site-key', 'auth_security.bot.secret_key' => 'private-secret', 'auth_security.bot.hostnames' => ['portal.example.test']]);
    }

    public function test_turnstile_outage_fails_closed_but_low_risk_login_needs_no_external_call(): void
    {
        Http::preventStrayRequests();
        $this->botConfiguration();
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::failedConnection()]);
        $this->wrong()->assertUnprocessable()->assertJsonValidationErrors('email');
        Http::assertNothingSent();
        config(['auth_security.bot.always' => true]);
        $this->postJson('/login', ['email' => 'unknown@example.test', 'password' => 'password', 'cf-turnstile-response' => 'token'])->assertStatus(503)->assertDontSee('private-secret');
        $this->assertGuest();
    }

    public function test_shared_ip_can_pass_soft_limit_with_bot_verification_but_not_hard_limit(): void
    {
        Http::preventStrayRequests();
        $this->botConfiguration();
        config(['auth_security.ip.attempts' => 2, 'auth_security.ip.hard_attempts' => 4]);
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'portal.example.test', 'action' => 'login'])]);
        $this->wrong('one@example.test')->assertUnprocessable();
        $this->wrong('two@example.test')->assertUnprocessable();
        $this->wrong('three@example.test')->assertUnprocessable()->assertJsonStructure(['bot_challenge']);
        $this->postJson('/login', ['email' => 'three@example.test', 'password' => 'incorrect', 'cf-turnstile-response' => 'token'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/login', ['email' => 'four@example.test', 'password' => 'incorrect', 'cf-turnstile-response' => 'token'])->assertTooManyRequests();
        Http::assertSentCount(1);
    }

    public function test_only_explicitly_trusted_proxies_can_supply_forwarded_ip(): void
    {
        $middleware = new TrustAuthenticationProxies;
        $request = Request::create('/login', server: ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.42']);
        $seen = null;
        $next = function (Request $request) use (&$seen) {
            $seen = $request->ip();

            return response('ok');
        };
        $middleware->handle($request, $next);
        $this->assertSame('192.0.2.10', $seen);
        config(['auth_security.trusted_proxies' => ['192.0.2.10']]);
        $middleware->handle($request, $next);
        $this->assertSame('198.51.100.42', $seen);
        Request::setTrustedProxies([], 0);
    }

    public function test_password_policy_rejects_common_breached_and_overlong_passwords_without_truncating(): void
    {
        foreach (['password123456', str_repeat('é', 37)] as $password) {
            $this->assertTrue(Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()])->fails());
        }
        $this->assertFalse(Validator::make(['password' => 'Una frase molto lunga da ricordare con facilità'], ['password' => PasswordPolicy::rules()])->fails());
        Http::preventStrayRequests();
        config(['auth_security.password.breach_check' => true]);
        $password = 'Known compromised passphrase';
        $hash = strtoupper(sha1($password));
        Http::fake(['https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":42\r\n")]);
        $this->assertTrue(Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()])->fails());
        Http::assertSent(fn ($request) => ! str_contains($request->url(), $password) && ! str_contains($request->url(), $hash));
    }

    public function test_registration_has_same_response_for_new_and_existing_accounts_and_never_changes_existing_password(): void
    {
        $data = ['name' => 'Customer', 'email' => 'customer@example.test', 'sender_type' => 'private', 'password' => 'A sufficiently long passphrase', 'password_confirmation' => 'A sufficiently long passphrase'];
        $expected = $this->postJson('/api/v1/customer/auth/register', $data)->assertAccepted()->json();
        $user = User::where('email', $data['email'])->sole();
        $hash = $user->password;
        $this->postJson('/api/v1/customer/auth/register', [...$data, 'password' => 'Another long secret passphrase', 'password_confirmation' => 'Another long secret passphrase'])->assertAccepted()->assertExactJson($expected);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertGuest('customer');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_security_logs_use_only_pseudonymous_context_and_no_credentials(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('security')->andReturn($logger);
        $logger->shouldReceive('notice')->withArgs(function (string $event, array $context): bool {
            $serialized = json_encode($context);
            $this->assertStringNotContainsString('incorrect-password', $serialized);
            $this->assertStringNotContainsString('unknown@example.test', $serialized);
            $this->assertStringNotContainsString('127.0.0.1', $serialized);
            $this->assertArrayHasKey('request_id', $context);

            return true;
        })->atLeast()->once();
        $this->wrong()->assertUnprocessable();
    }

    public function test_verified_device_can_recover_targeted_account_but_still_requires_password_and_otp(): void
    {
        $this->freezeTime();
        $user = $this->pending();
        $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
        $response = $this->post('/verify-email-otp/confirm', ['code' => $code])->assertRedirect('/dashboard');
        $proof = $response->getCookie('ea_staff_device')->getValue();
        $this->post('/logout');
        $this->travel(61)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $this->wrong($user->email)->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
        $this->withCookie('ea_staff_device', $proof)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $this->assertSame(StaffAuthenticationState::PrimaryAuthenticated->value, session('staff_authentication.state'));
        $this->get('/dashboard')->assertRedirect(route('email-otp.notice'));
        $this->post('/verify-email-otp/confirm', ['code' => Notification::sent($user, RiderEmailOtp::class)->last()->code])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_device_proof_cannot_be_forged_reused_for_another_account_or_used_without_limits(): void
    {
        $this->freezeTime();
        $user = $this->pending();
        $response = $this->post('/verify-email-otp/confirm', ['code' => Notification::sent($user, RiderEmailOtp::class)->last()->code]);
        $proof = $response->getCookie('ea_staff_device')->getValue();
        $this->post('/logout');
        $this->travel(61)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $this->wrong($user->email)->assertUnprocessable();
        }
        $this->withCookie('ea_staff_device', $proof.'invalid')->wrong($user->email)->assertTooManyRequests();
        $otherRequest = Request::create('/login', cookies: ['ea_staff_device' => $proof]);
        $this->assertFalse(app(StaffDeviceProof::class)->allows($otherRequest, 'other@example.test'));
        $this->withCookie('ea_staff_device', $proof);
        for ($i = 0; $i < 3; $i++) {
            $this->wrong($user->email)->assertUnprocessable();
        }
        $this->wrong($user->email)->assertTooManyRequests();
        $this->travel(604800)->seconds();
        $this->assertFalse(app(StaffDeviceProof::class)->allows($otherRequest, $user->email));
    }

    public function test_log_mailer_and_log_fallback_never_receive_plaintext_otp(): void
    {
        Notification::fake();
        config(['auth_security.otp.mailer' => 'log']);
        $user = User::factory()->unverified()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'))->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_otp_hash);
        config(['auth_security.otp.mailer' => 'failover']);
        $this->post('/verify-email-otp/send')->assertSessionHasErrors('code');
        Notification::assertNothingSent();
    }

    public function test_password_breach_service_outage_rejects_new_password_without_sending_it(): void
    {
        Http::preventStrayRequests();
        config(['auth_security.password.breach_check' => true]);
        Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::failedConnection()]);
        $validator = Validator::make(['password' => 'A long private passphrase'], ['password' => PasswordPolicy::rules()]);
        $this->assertTrue($validator->fails());
        $this->assertSame('Verifica della password non disponibile. Riprova tra poco.', $validator->errors()->first('password'));
    }

    public function test_distinct_account_and_ip_patterns_are_aggregated_without_notifying_each_request(): void
    {
        Queue::fake([SendAuthSecurityAlert::class]);
        $monitor = app(AuthSecurityMonitor::class);
        $this->assertFalse($monitor->pattern('password_spraying', 'network', 'one', 3));
        $this->assertFalse($monitor->pattern('password_spraying', 'network', 'two', 3));
        $this->assertTrue($monitor->pattern('password_spraying', 'network', 'three', 3));
        $this->assertTrue($monitor->pattern('password_spraying', 'network', 'four', 3));
        Queue::assertPushed(SendAuthSecurityAlert::class, 1);
    }

    public function test_production_rejects_process_local_counter_stores(): void
    {
        $this->get('/login')->assertOk();
        $token = session()->token();
        $this->app['env'] = 'production';
        $this->postJson('/login', ['_token' => $token, 'email' => 'unknown@example.test', 'password' => 'incorrect-password'])->assertStatus(503);
        $this->assertGuest();
    }

    public function test_many_targeted_accounts_cannot_generate_unbounded_alert_deliveries(): void
    {
        Queue::fake([SendAuthSecurityAlert::class]);
        for ($i = 0; $i < 50; $i++) {
            app(AuthSecurityMonitor::class)->alert('account_targeted', 'account'.$i);
        }
        Queue::assertPushed(SendAuthSecurityAlert::class, 1);
    }

    public function test_missing_account_performs_a_password_hash_check_before_generic_rejection(): void
    {
        $hasher = \Mockery::mock(Hasher::class);
        $hasher->shouldReceive('check')->once()->with('probe-secret', config('auth_security.dummy_password_hash'))->andReturn(false);
        $provider = new AuthUserProvider($hasher, User::class);

        $this->assertNull($provider->retrieveByCredentials(['email' => 'missing@example.test', 'password' => 'probe-secret']));
    }
}
