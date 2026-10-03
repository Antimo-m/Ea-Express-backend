<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_from_another_session_is_rejected_without_consuming_the_owner_challenge(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post('/verify-email-otp/send')->assertSessionHasNoErrors();
        $code = Notification::sent($user, RiderEmailOtp::class)->sole()->code;
        $challenge = session('email_otp_challenge');
        $this->withSession(['email_otp_challenge' => null])->postJson('/verify-email-otp/confirm', ['code' => $code])->assertInvalid('code');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNotNull($user->fresh()->email_otp_hash);

        $this->withSession(['email_otp_challenge' => $challenge])->post('/verify-email-otp/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull(session('email_otp_challenge'));
    }

    public function test_account_swapping_cannot_verify_a_different_rider(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        $target = User::factory()->unverified()->create();
        $this->actingAs($owner)->post('/verify-email-otp/send');
        $code = Notification::sent($owner, RiderEmailOtp::class)->sole()->code;
        $this->actingAs($target)->postJson('/verify-email-otp/confirm', ['code' => $code, 'user_id' => $owner->id])->assertInvalid('code');
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertNull($target->fresh()->email_verified_at);
    }

    public static function portals(): array
    {
        return ['staff' => ['/login', UserRole::Rider], 'customer' => ['/api/v1/customer/auth/login', UserRole::Customer]];
    }

    #[DataProvider('portals')]
    public function test_rotating_ips_cannot_bypass_login_account_limits(string $path, UserRole $role): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => $role]);
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->postJson($path, ['email' => $user->email, 'password' => 'wrong'])->assertInvalid('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->postJson($path, ['email' => strtoupper($user->email), 'password' => 'password'])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $response = $this->postJson($path, ['email' => $user->email, 'password' => 'password']);
        $role === UserRole::Customer ? $response->assertOk() : $response->assertRedirect('/dashboard');
    }

    public function test_malformed_email_in_customer_limiter_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/customer/auth/login', ['email' => ['invalid'], 'password' => 'password'])->assertInvalid('email');
    }

    public static function unsafeDestinations(): array
    {
        return ['external' => ['https://evil.example/path'], 'scheme relative' => ['//evil.example/path'], 'backslash' => ['/\\evil.example'], 'script' => ['javascript:alert(1)'], 'credentials' => ['https://user@localhost/path']];
    }

    #[DataProvider('unsafeDestinations')]
    public function test_login_rejects_unsafe_intended_destinations(string $destination): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => $destination])->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_login_preserves_internal_navigation_and_rotates_the_session(): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => '/orders/history?status=delivered']);
        $old = session()->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/orders/history?status=delivered');
        $this->assertNotSame($old, session()->getId());
    }

    public function test_oversized_raw_body_is_rejected_even_with_a_false_content_length(): void
    {
        $this->call('POST', '/api/v1/customer/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => '1'], '{"email":"'.str_repeat('a', 262144).'"}')->assertStatus(413);
        $this->assertGuest('customer');
    }

    public function test_deep_and_wide_json_are_rejected_before_validation(): void
    {
        $body = str_repeat('{"a":', 40).'1'.str_repeat('}', 40);
        $this->call('POST', '/api/v1/customer/auth/register', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertStatus(413);
        $this->postJson('/api/v1/customer/auth/register', ['items' => array_fill(0, 2001, 'x')])->assertStatus(413);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_deep_form_and_oversized_upload_are_rejected(): void
    {
        $data = ['x' => 'value'];
        for ($i = 0; $i < 20; $i++) {
            $data = ['nested' => $data];
        }
        $this->post('/login', $data)->assertStatus(413);
        $this->post('/login', ['file' => UploadedFile::fake()->create('large.bin', 10240)])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_malformed_json_does_not_silently_become_empty_input(): void
    {
        $this->call('POST', '/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{broken')->assertBadRequest();
    }

    public function test_unbounded_pagination_and_large_query_strings_are_rejected(): void
    {
        $this->getJson('/api/v1/customer/orders?page=999999999')->assertUnprocessable();
        $this->getJson('/balance?cash_page[]=1')->assertUnprocessable();
        $this->getJson('/login?q='.str_repeat('x', 17000))->assertStatus(413);
    }

    public function test_request_limits_preserve_valid_large_checkout_payloads(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['checkout_token' => str_repeat('a', 30000), 'packages' => array_fill(0, 100, ['weight_kg' => 1, 'length_cm' => 1, 'width_cm' => 1, 'height_cm' => 1])])->assertInvalid('email');
    }

    public function test_failed_login_logs_context_without_credentials(): void
    {
        Log::spy();
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-test-password'])->assertInvalid('email');
        Log::shouldHaveReceived('notice')->once()->with('security.login_failed', \Mockery::on(function (array $context) use ($user): bool {
            $this->assertSame($user->id, $context['user_id']);
            $this->assertArrayHasKey('request_id', $context);
            $this->assertStringNotContainsString('secret-test-password', json_encode($context));
            $this->assertArrayNotHasKey('email', $context);

            return true;
        }));
    }
}
