<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RiderActivationLink;
use App\Notifications\RiderEmailOtp;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
