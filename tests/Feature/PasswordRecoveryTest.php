<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\PasswordResetLink;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function customerToken(User $user): string
    {
        $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $url = Notification::sent($user, PasswordResetLink::class)->last()->url($user);
        parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $fragment);

        return $fragment['token'];
    }

    public function test_customer_resets_the_password_from_the_email_link_and_must_log_in_again_with_otp(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => UserRole::Customer]);
        DB::table('trusted_rider_devices')->insert(['slug' => '00000000-0000-4000-8000-000000000001', 'user_id' => $user->id, 'token_hash' => str_repeat('a', 64), 'credential_proof' => str_repeat('b', 64), 'authorized_at' => now(), 'last_used_at' => now(), 'expires_at' => now()->addDay(), 'revoked_at' => null]);
        $version = (int) $user->auth_version;
        $token = $this->customerToken($user);

        $this->postJson('/api/v1/customer/auth/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'Brand-new-password-1', 'password_confirmation' => 'Brand-new-password-1'])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('Brand-new-password-1', $user->password));
        $this->assertGreaterThan($version, (int) $user->auth_version);
        $this->assertNotNull(DB::table('trusted_rider_devices')->value('revoked_at'));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest('customer');
        Notification::assertSentTo($user, PasswordChanged::class);
        $this->postJson('/api/v1/customer/auth/login', ['email' => $user->email, 'password' => 'Brand-new-password-1'])->assertAccepted()->assertJsonPath('otp_required', true);
        Notification::assertSentTo($user, RiderEmailOtp::class);
    }

    public function test_link_expires_after_fifteen_minutes_and_a_new_request_replaces_the_previous_link(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create(['role' => UserRole::Customer]);
        $first = $this->customerToken($user);
        $this->travel(61)->seconds();
        $second = $this->customerToken($user);
        $payload = ['email' => $user->email, 'password' => 'Brand-new-password-1', 'password_confirmation' => 'Brand-new-password-1'];

        $this->postJson('/api/v1/customer/auth/password/reset', [...$payload, 'token' => $first])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->travel(15)->minutes();
        $this->postJson('/api/v1/customer/auth/password/reset', [...$payload, 'token' => $second])->assertUnprocessable();

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_customer_and_rider_links_cannot_be_used_on_the_other_portal(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $rider = User::factory()->create();

        $this->post('/forgot-password', ['email' => $customer->email])->assertRedirect();
        $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => $rider->email])->assertAccepted();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_rider_resets_the_password_from_the_staff_pages(): void
    {
        Notification::fake();
        $rider = User::factory()->create();
        $this->post('/forgot-password', ['email' => $rider->email])->assertRedirect()->assertSessionHas('status');
        $url = Notification::sent($rider, PasswordResetLink::class)->last()->url($rider);
        $this->assertStringContainsString('/reset-password/', $url);

        $this->get($url)->assertOk()->assertSee($rider->email);
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        $this->post('/reset-password', ['email' => $rider->email, 'token' => $token, 'password' => 'Rider-new-password-1', 'password_confirmation' => 'Rider-new-password-1'])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Rider-new-password-1', $rider->fresh()->password));
        $this->assertGuest();
        $this->post('/login', ['email' => $rider->email, 'password' => 'Rider-new-password-1'])->assertRedirect(route('email-otp.notice'));
    }

    public function test_inactive_accounts_never_receive_a_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);

        $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => $user->email])->assertAccepted();

        Notification::assertNothingSent();
    }

    public function test_wrong_tokens_are_rate_limited_per_account(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer]);
        $payload = ['email' => $user->email, 'password' => 'Brand-new-password-1', 'password_confirmation' => 'Brand-new-password-1'];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->postJson('/api/v1/customer/auth/password/reset', [...$payload, 'token' => 'wrong'.$attempt])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->postJson('/api/v1/customer/auth/password/reset', [...$payload, 'token' => 'wrong'])->assertTooManyRequests();
    }

    public function test_rider_and_customer_can_still_change_the_password_but_the_admin_cannot(): void
    {
        Notification::fake();
        $rider = User::factory()->create();
        $this->actingAs($rider)->put('/password', ['current_password' => 'password', 'password' => 'Rider-new-password-1', 'password_confirmation' => 'Rider-new-password-1'])->assertRedirect(route('login'));
        Notification::assertSentTo($rider, PasswordChanged::class);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $hash = $admin->password;
        $this->actingAs($admin)->get('/profile')->assertOk()->assertSee('data-admin-password-locked', false)->assertDontSee('id="new-password"', false)->assertDontSee(route('password.update'), false);
        $this->actingAs($admin)->put('/password', ['current_password' => 'password', 'password' => 'Admin-new-password-1', 'password_confirmation' => 'Admin-new-password-1'])->assertForbidden();
        $this->assertSame($hash, $admin->fresh()->password);
        Notification::assertNothingSentTo($admin);
    }

    public function test_console_command_sets_the_admin_password_and_revokes_sessions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $version = (int) $admin->auth_version;

        $this->artisan('app:set-password', ['email' => strtoupper($admin->email)])
            ->expectsQuestion('Nuova password (almeno 12 caratteri)', 'Console-password-123')
            ->expectsQuestion('Conferma la nuova password', 'Console-password-123')
            ->expectsConfirmation('Confermi la nuova password per '.$admin->email.' (admin)? Tutte le sessioni e i dispositivi verranno revocati.', 'yes')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('Console-password-123', $admin->password));
        $this->assertGreaterThan($version, (int) $admin->auth_version);
        $this->assertDatabaseHas('economic_audits', ['action' => 'user.password-set-cli', 'entity_id' => $admin->id, 'user_id' => null]);
    }

    public function test_console_command_rejects_weak_or_mismatched_passwords_and_unknown_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $hash = $admin->password;

        $this->artisan('app:set-password', ['email' => $admin->email])
            ->expectsQuestion('Nuova password (almeno 12 caratteri)', 'short')
            ->expectsQuestion('Conferma la nuova password', 'different')
            ->assertFailed();
        $this->artisan('app:set-password', ['email' => 'missing@example.test'])->assertFailed();

        $this->assertSame($hash, $admin->fresh()->password);
    }
}
