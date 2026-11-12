<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountSessionVersion;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GlobalSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private array $devices = [];

    private ?string $currentDevice = null;

    private string $sessionDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionDirectory = sys_get_temp_dir().'/ea-revocation-'.bin2hex(random_bytes(8));
        mkdir($this->sessionDirectory, 0700);
        config(['session.driver' => 'file', 'session.files' => $this->sessionDirectory]);
    }

    protected function tearDown(): void
    {
        DB::purge('session_test');
        File::deleteDirectory($this->sessionDirectory);
        parent::tearDown();
    }

    private function device(string $name): void
    {
        if ($this->currentDevice !== null) {
            app('session.store')->save();
            $this->devices[$this->currentDevice] = session()->getId();
        }
        Auth::forgetGuards();
        Auth::shouldUse('web');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $session = app('session.store');
        $session->setId($this->devices[$name] ?? Str::random(40));
        $session->start();
        $this->defaultCookies = [];
        app('cookie')->unqueue('ea_trusted_rider');
        app('cookie')->unqueue('ea_staff_device');
        $this->currentDevice = $name;
    }

    private function login(User $user, bool $remember = false): TestResponse
    {
        Notification::fake();
        if ($user->role === UserRole::Customer) {
            return $this->postJson('/api/v1/customer/auth/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertOk();
        } else {
            $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertRedirect(route('email-otp.notice'));
            $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;

            return $this->post('/verify-email-otp/confirm', ['code' => $code])->assertRedirect('/dashboard');
        }
    }

    public function test_idle_customer_session_stays_revoked_after_deactivation_and_reactivation(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->device('customer-a');
        $this->login($customer);
        $this->getJson('/api/v1/customer/auth/me')->assertOk()->assertJsonPath('user.slug', $customer->slug);
        $this->device('customer-b');
        $this->login($customer);
        $this->getJson('/api/v1/customer/auth/me')->assertOk()->assertJsonPath('user.slug', $customer->slug);
        $this->device('admin');
        $this->login($admin);
        $this->patch(route('users.update', $customer), ['action' => 'deactivate'])->assertRedirect();
        $this->patch(route('users.update', $customer), ['action' => 'activate'])->assertRedirect();

        foreach (['customer-a', 'customer-b'] as $device) {
            $this->device($device);
            $this->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
        }
    }

    public function test_stale_model_updates_cannot_reuse_or_regress_security_version(): void
    {
        $user = User::factory()->create();
        $stale = $user->fresh();
        $user->forceFill(['is_active' => false])->save();
        $stale->forceFill(['email' => 'changed@example.test'])->save();
        $this->assertSame(2, (int) $stale->fresh()->auth_version);
    }

    private function sessionDriver(string $driver): void
    {
        if ($driver === 'redis') {
            if (getenv('EA_TEST_REDIS') !== '1' || ! extension_loaded('redis')) {
                $this->markTestSkipped('Richiede EA_TEST_REDIS=1 e una istanza Redis di test isolata.');
            }
            config([
                'database.redis.client' => 'phpredis',
                'database.redis.revocation_test' => ['host' => getenv('EA_TEST_REDIS_SOCKET') ?: '127.0.0.1', 'port' => getenv('EA_TEST_REDIS_SOCKET') ? 0 : (int) (getenv('EA_TEST_REDIS_PORT') ?: 6379), 'database' => 15, 'password' => null],
                'session.driver' => 'redis', 'session.connection' => 'revocation_test',
                'session.prefix' => basename($this->sessionDirectory).':',
            ]);
        } elseif ($driver === 'database-alternative') {
            config(['database.connections.session_test' => ['driver' => 'sqlite', 'database' => $this->sessionDirectory.'/sessions.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
            touch($this->sessionDirectory.'/sessions.sqlite');
            Schema::connection('session_test')->create('session_test_rows', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
            config(['session.driver' => 'database', 'session.connection' => 'session_test', 'session.table' => 'session_test_rows']);
        } else {
            config(['session.driver' => $driver, 'session.connection' => null, 'session.table' => 'sessions']);
        }
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }

    public static function passwordDrivers(): array
    {
        $cases = [];
        foreach (['file', 'database', 'database-alternative', 'redis'] as $driver) {
            foreach (UserRole::cases() as $role) {
                $cases[$driver.' '.$role->value] = [$driver, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('passwordDrivers')]
    public function test_password_change_requires_a_new_login_on_every_device(string $driver, UserRole $role): void
    {
        $this->sessionDriver($driver);
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $path = $role === UserRole::Customer ? '/api/v1/customer/auth/me' : '/dashboard';
        $this->device('other-account');
        $this->login($other);
        $this->device('a');
        $this->login($user);
        $this->device('b');
        $this->travel(61)->seconds();
        $this->login($user);
        $this->device('a');
        $this->getJson($path)->assertOk();
        $previousSession = session()->getId();
        $previousCsrf = session()->token();

        $response = $this->putJson($role === UserRole::Customer ? '/api/v1/customer/password' : '/password', ['current_password' => 'password', 'password' => 'Updated-password-123', 'password_confirmation' => 'Updated-password-123']);
        $role === UserRole::Customer ? $response->assertOk() : $response->assertRedirect();

        $this->assertNotSame($previousSession, session()->getId());
        $this->assertNotSame($previousCsrf, session()->token());
        $this->assertGuest($role === UserRole::Customer ? 'customer' : 'web');
        $this->assertContains($this->getJson($path)->status(), [401, 403]);
        $this->device('b');
        $this->assertContains($this->getJson($path)->status(), [401, 403]);
        $this->assertGuest($role === UserRole::Customer ? 'customer' : 'web');
        $this->device('other-account');
        $this->getJson('/api/v1/customer/auth/me')->assertOk()->assertJsonPath('user.slug', $other->slug);

        $this->device('a');
        $this->travel(61)->seconds();
        $credentials = ['email' => $user->email, 'password' => 'Updated-password-123'];
        if ($role === UserRole::Customer) {
            $this->postJson('/api/v1/customer/auth/login', $credentials)->assertOk();
        } else {
            $this->post('/login', $credentials)->assertRedirect(route('email-otp.notice'));
            $code = Notification::sent($user, RiderEmailOtp::class)->last()->code;
            $this->post('/verify-email-otp/confirm', ['code' => $code])->assertRedirect('/dashboard');
        }
        $this->getJson($path)->assertOk();
    }

    public static function revocationDrivers(): array
    {
        return ['file' => ['file'], 'database' => ['database'], 'alternative database' => ['database-alternative'], 'redis' => ['redis']];
    }

    #[DataProvider('revocationDrivers')]
    public function test_two_idle_rider_sessions_do_not_recover_after_admin_deactivates_then_reactivates(string $driver): void
    {
        $this->sessionDriver($driver);
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['a', 'b'] as $device) {
            $this->device($device);
            $this->travel(61)->seconds();
            $this->login($rider);
            $this->getJson('/dashboard')->assertOk();
        }
        $this->device('admin');
        $this->login($admin);
        $this->patch(route('users.update', $rider), ['action' => 'deactivate'])->assertRedirect();
        $this->patch(route('users.update', $rider), ['action' => 'activate'])->assertRedirect();
        $this->assertSame(2, (int) $rider->fresh()->auth_version);
        foreach (['a', 'b'] as $device) {
            $this->device($device);
            $this->assertContains($this->getJson('/dashboard')->status(), [401, 403]);
            $this->assertGuest('web');
        }
        $this->device('new');
        $this->travel(61)->seconds();
        $this->login($rider->fresh());
        $this->getJson('/dashboard')->assertOk();
    }

    public function test_reset_email_revokes_pending_otp_and_complete_session(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->device('complete');
        $this->login($rider);
        $this->device('pending');
        $this->travel(61)->seconds();
        Notification::fake();
        $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('email-otp.notice'));
        $code = Notification::sent($rider, RiderEmailOtp::class)->last()->code;
        $this->device('admin');
        $this->login($admin);
        $this->patch(route('users.update', $rider), ['action' => 'reset-email'])->assertRedirect();
        $this->assertNull($rider->fresh()->email_otp_hash);
        $this->device('pending');
        $this->postJson('/verify-email-otp/confirm', ['code' => $code])->assertForbidden();
        $this->device('complete');
        $this->getJson('/dashboard')->assertForbidden();
    }

    public static function invalidProofs(): array
    {
        return ['absent' => [null], 'string' => [['user_id' => 1, 'version' => '0']], 'negative' => [['user_id' => 1, 'version' => -1]], 'foreign user' => [['user_id' => 99999, 'version' => 0]], 'future' => [['user_id' => 1, 'version' => 99999]]];
    }

    #[DataProvider('invalidProofs')]
    public function test_missing_or_manipulated_session_versions_fail_closed(?array $proof): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->device('customer');
        $this->login($customer);
        session()->put('account_authentication.customer', $proof);
        session()->save();
        $this->getJson('/api/v1/customer/auth/me?auth_version=0')->assertUnauthorized();
        $this->assertGuest('customer');
        $this->assertNull(session('account_authentication'));
    }

    public function test_without_version_guard_file_customer_session_would_survive_reactivation(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->device('customer');
        $this->login($customer);
        $customer->forceFill(['is_active' => false])->save();
        $customer->refresh()->forceFill(['is_active' => true])->save();
        $this->device('idle');
        $this->device('customer');
        $this->withoutMiddleware(EnsureAccountSessionVersion::class);
        $this->getJson('/api/v1/customer/auth/me')->assertOk();
    }

    public function test_old_remember_cookie_cannot_reauthenticate_after_password_change(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->device('a');
        $response = $this->login($customer, true);
        $cookieName = Auth::guard('customer')->getRecallerName();
        $remember = $response->getCookie($cookieName)->getValue();
        $this->putJson('/api/v1/customer/password', ['current_password' => 'password', 'password' => 'Updated-password-123', 'password_confirmation' => 'Updated-password-123'])->assertOk();
        $this->device('copied-remember');
        $this->withCookie($cookieName, $remember)->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
        $this->assertGuest('customer');
    }

    public static function accountChanges(): array
    {
        return ['role' => ['role'], 'email' => ['email'], 'deletion' => ['deletion']];
    }

    #[DataProvider('accountChanges')]
    public function test_account_changes_reject_the_previous_customer_session(string $change): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->device('customer');
        $this->login($customer);
        $this->getJson('/api/v1/customer/auth/me')->assertOk();

        if ($change === 'deletion') {
            $customer->delete();
            $this->assertModelMissing($customer);
        } else {
            $customer->forceFill($change === 'role' ? ['role' => UserRole::Rider] : ['email' => 'updated@example.test'])->save();
            $this->assertSame(1, (int) $customer->fresh()->auth_version);
        }

        $this->device('idle');
        $this->device('customer');
        $this->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
        $this->assertGuest('customer');
    }

    public function test_repeated_admin_revocation_increments_version_even_when_account_fields_are_unchanged(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->device('rider');
        $this->login($rider);
        $this->device('admin');
        $this->login($admin);

        $this->patch(route('users.update', $rider), ['action' => 'revoke-devices'])->assertRedirect();
        $this->patch(route('users.update', $rider), ['action' => 'revoke-devices'])->assertRedirect();

        $this->assertSame(2, (int) $rider->fresh()->auth_version);
        $this->device('rider');
        $this->getJson('/dashboard')->assertForbidden();
    }
}
