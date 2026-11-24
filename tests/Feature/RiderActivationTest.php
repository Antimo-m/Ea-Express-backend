<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderActivationLink;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RiderActivationTest extends TestCase
{
    use RefreshDatabase;

    private function createRider(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Mario Rider', 'email' => 'mario.rider@example.test'])->assertRedirect(route('users.index'))->assertSessionHas('status');
        $this->post(route('logout'));

        return User::where('email', 'mario.rider@example.test')->sole();
    }

    private function token(User $rider): string
    {
        $notification = Notification::sent($rider, RiderActivationLink::class)->last();

        return basename((string) parse_url($notification->url($rider), PHP_URL_PATH));
    }

    private function activate(User $rider, string $token, string $password = 'Rider-password-123'): TestResponse
    {
        return $this->post('/activate', ['email' => $rider->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_new_rider_chooses_the_password_from_the_email_and_then_logs_in_with_otp(): void
    {
        Notification::fake();
        $rider = $this->createRider();
        $notification = Notification::sent($rider, RiderActivationLink::class)->sole();
        $this->assertStringContainsString('48 ore', implode(' ', $notification->toMail($rider)->outroLines));
        $this->get($notification->url($rider))->assertOk()->assertSee($rider->email)->assertSee('Crea la tua password.');

        $this->activate($rider, $this->token($rider))->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $rider->refresh();
        $this->assertTrue(Hash::check('Rider-password-123', $rider->password));
        $this->assertNotNull($rider->email_verified_at);
        $this->assertGuest();
        $this->assertDatabaseHas('economic_audits', ['action' => 'rider.activated', 'entity_id' => $rider->id]);
        $this->post('/login', ['email' => $rider->email, 'password' => 'Rider-password-123'])->assertRedirect(route('email-otp.notice'));
        Notification::assertSentTo($rider, RiderEmailOtp::class);
    }

    public function test_link_expires_after_48_hours_and_works_only_once(): void
    {
        Notification::fake();
        $this->freezeTime();
        $rider = $this->createRider();
        $token = $this->token($rider);
        $this->travel(48 * 60 + 1)->minutes();

        $this->activate($rider, $token)->assertSessionHasErrors('email');

        $this->travelBack();
        $second = User::factory()->create(['role' => UserRole::Rider, 'email_verified_at' => null]);
        $admin = User::where('role', UserRole::Admin)->sole();
        $this->actingAs($admin)->patch(route('users.update', $second), ['action' => 'send-activation'])->assertRedirect();
        $this->post(route('logout'));
        $token = $this->token($second);
        $this->activate($second, $token)->assertRedirect(route('login'));
        $this->activate($second, $token, 'Another-password-123')->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Rider-password-123', $second->fresh()->password));
    }

    public function test_admin_can_resend_the_link_and_the_previous_one_stops_working(): void
    {
        Notification::fake();
        $rider = $this->createRider();
        $first = $this->token($rider);
        $admin = User::where('role', UserRole::Admin)->sole();
        $this->travel(61)->seconds();

        $this->actingAs($admin)->get(route('users.index'))->assertSee('Invia nuovo link di attivazione');
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'send-activation'])->assertRedirect()->assertSessionHas('status');
        $this->post(route('logout'));

        Notification::assertSentToTimes($rider, RiderActivationLink::class, 2);
        $this->activate($rider, $first)->assertSessionHasErrors('email');
        $this->activate($rider, $this->token($rider))->assertRedirect(route('login'));
    }

    public function test_activation_cannot_target_verified_riders_customers_or_the_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $verified = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer, 'email_verified_at' => null]);

        foreach ([$verified, $customer, $admin] as $target) {
            $this->actingAs($admin)->patch(route('users.update', $target), ['action' => 'send-activation'])->assertStatus($target->is($admin) ? 403 : 422);
        }
        Notification::assertNothingSent();

        $this->post(route('logout'));
        $this->activate($customer, 'forged-token')->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $customer->fresh()->password));
    }

    public function test_deactivating_a_rider_invalidates_the_pending_activation_link(): void
    {
        Notification::fake();
        $rider = $this->createRider();
        $token = $this->token($rider);
        $admin = User::where('role', UserRole::Admin)->sole();

        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'deactivate'])->assertRedirect();
        $this->actingAs($admin)->patch(route('users.update', $rider), ['action' => 'activate'])->assertRedirect();
        $this->post(route('logout'));

        $this->activate($rider, $token)->assertSessionHasErrors('email');
    }

    public function test_successful_activation_gives_the_browser_the_staff_gate_pass_unencrypted(): void
    {
        config(['auth_security.staff_gate.pass' => 'gate-pass-for-tests', 'session.secure' => true]);
        Notification::fake();
        $this->freezeTime();
        $rider = $this->createRider();

        $cookie = $this->activate($rider, $this->token($rider))->assertRedirect(route('login'))->getCookie('ea_staff', decrypt: false);

        $this->assertNotNull($cookie);
        $this->assertSame('gate-pass-for-tests', $cookie->getValue());
        $this->assertSame(now()->addDays(180)->getTimestamp(), $cookie->getExpiresTime());
        $this->assertSame(['/', null, true, true, 'lax'], [$cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly(), $cookie->getSameSite()]);
    }

    public function test_opening_the_link_or_using_an_invalid_one_never_gives_the_staff_gate_pass(): void
    {
        config(['auth_security.staff_gate.pass' => 'gate-pass-for-tests']);
        Notification::fake();
        $rider = $this->createRider();
        $token = $this->token($rider);

        $this->get('/activate/'.$token.'?email='.$rider->email)->assertOk()->assertCookieMissing('ea_staff');
        $this->activate($rider, 'forged-token')->assertSessionHasErrors('email')->assertCookieMissing('ea_staff');
        $this->activate($rider, $token, 'corta')->assertSessionHasErrors('password')->assertCookieMissing('ea_staff');
        $this->travel(48 * 60 + 1)->minutes();
        $this->activate($rider, $token)->assertSessionHasErrors('email')->assertCookieMissing('ea_staff');
    }

    public function test_used_link_does_not_give_the_staff_gate_pass_again(): void
    {
        config(['auth_security.staff_gate.pass' => 'gate-pass-for-tests']);
        Notification::fake();
        $rider = $this->createRider();
        $token = $this->token($rider);
        $this->activate($rider, $token)->assertCookie('ea_staff', 'gate-pass-for-tests', encrypted: false);
        Cookie::flushQueuedCookies();

        $this->activate($rider, $token, 'Another-password-123')->assertSessionHasErrors('email')->assertCookieMissing('ea_staff');
    }

    public function test_activation_works_without_a_configured_staff_gate_pass(): void
    {
        config(['auth_security.staff_gate.pass' => null]);
        Notification::fake();
        $rider = $this->createRider();

        $this->activate($rider, $this->token($rider))->assertRedirect(route('login'))->assertSessionHasNoErrors()->assertCookieMissing('ea_staff');
    }
}
