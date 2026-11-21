<?php

namespace Tests\Feature;

use App\Jobs\SendPickupReminder;
use App\Mail\PickupReminder;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\MailLinkAdvice;
use App\UserRole;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PickupReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.order_lifecycle_enabled' => true, 'mail.pickup_reminders_enabled' => true]);
        Mail::fake();
        Queue::fake();
    }

    private function order(User $customer, string $date, OrderStatus $status = OrderStatus::Accepted): Order
    {
        return Order::factory()->create(['customer_id' => $customer->id, 'contact_email' => $customer->email, 'pickup_date' => $date, 'status' => $status, 'pickup_from' => '09:00', 'pickup_to' => '12:00']);
    }

    /** @return list<object> */
    private function deliveries(): array
    {
        return DB::table('pickup_reminder_deliveries')->orderBy('id')->get()->all();
    }

    public function test_evening_groups_tomorrow_pickups_per_customer_once_and_skips_closed_or_collected_orders(): void
    {
        $this->travelTo(Carbon::parse('2026-11-22 18:00', 'Europe/Rome'));
        $shop = User::factory()->create(['role' => UserRole::Customer, 'email' => 'negozio@example.test']);
        $other = User::factory()->create(['role' => UserRole::Customer, 'email' => 'altro@example.test']);
        $tomorrow = collect(range(1, 3))->map(fn () => $this->order($shop, '2026-11-23'));
        $this->order($shop, '2026-11-23', OrderStatus::Cancelled);
        $collected = $this->order($shop, '2026-11-23', OrderStatus::PickedUp);
        $collected->events()->create(['status' => OrderStatus::PickedUp, 'user_id' => $shop->id]);
        $this->order($shop, '2026-11-22');
        $this->order($shop, '2026-11-24');
        $this->order($other, '2026-11-23');

        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();
        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();

        $deliveries = $this->deliveries();
        $this->assertCount(2, $deliveries, 'Una sola email per cliente, anche rilanciando il comando.');
        $shopDelivery = collect($deliveries)->firstWhere('recipient', 'negozio@example.test');
        $this->assertEqualsCanonicalizing($tomorrow->pluck('id')->all(), array_column(json_decode($shopDelivery->snapshot, true)['orders'], 'id'));
        $this->assertSame(4, DB::table('pickup_reminder_orders')->count());
        Queue::assertPushed(SendPickupReminder::class, 2);

        foreach ($deliveries as $delivery) {
            (new SendPickupReminder($delivery->id))->handle();
        }
        Mail::assertSent(PickupReminder::class, 2);
        Mail::assertSent(PickupReminder::class, fn (PickupReminder $mail): bool => $mail->hasTo('negozio@example.test') && count($mail->snapshot['orders']) === 3 && $mail->kind === 'evening');
        $html = (new PickupReminder(json_decode($shopDelivery->snapshot, true), 'evening'))->render();
        $this->assertStringContainsString('Domani passiamo a ritirare', $html);
        $this->assertStringContainsString('hai 3 ritiri programmati', $html);
        $this->assertStringContainsString($tomorrow->first()->reference, $html);
        $this->assertStringContainsString(e(MailLinkAdvice::Text), $html);
        $this->assertSame(['sent', 'sent'], array_column($this->deliveries(), 'state'));
    }

    public function test_morning_covers_late_bookings_and_rescheduled_pickups_get_the_new_date_reminder(): void
    {
        $shop = User::factory()->create(['role' => UserRole::Customer, 'email' => 'negozio@example.test']);
        $this->travelTo(Carbon::parse('2026-11-22 18:00', 'Europe/Rome'));
        $early = $this->order($shop, '2026-11-23');
        $moved = $this->order($shop, '2026-11-23');
        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();

        // Prenotazione dopo l'invio della sera e un ritiro spostato al giorno seguente.
        $this->travelTo(Carbon::parse('2026-11-22 23:01', 'Europe/Rome'));
        $late = $this->order($shop, '2026-11-23');
        $moved->forceFill(['pickup_date' => '2026-11-24'])->save();

        $this->travelTo(Carbon::parse('2026-11-23 07:30', 'Europe/Rome'));
        $this->artisan('orders:queue-pickup-reminders morning')->assertSuccessful();
        $morning = collect($this->deliveries())->firstWhere('kind', 'morning');
        $this->assertSame([$late->id], array_column(json_decode($morning->snapshot, true)['orders'], 'id'), 'Il mattino ricorda solo chi non ha ricevuto il promemoria della sera.');
        $this->assertNotContains($early->id, array_column(json_decode($morning->snapshot, true)['orders'], 'id'));

        $this->travelTo(Carbon::parse('2026-11-23 18:00', 'Europe/Rome'));
        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();
        $evening = collect($this->deliveries())->where('kind', 'evening')->firstWhere('pickup_date', '2026-11-24');
        $this->assertSame([$moved->id], array_column(json_decode($evening->snapshot, true)['orders'], 'id'));
    }

    public function test_orders_cancelled_before_sending_are_removed_and_an_empty_reminder_is_not_sent(): void
    {
        $this->travelTo(Carbon::parse('2026-11-22 18:00', 'Europe/Rome'));
        $shop = User::factory()->create(['role' => UserRole::Customer, 'email' => 'negozio@example.test']);
        $kept = $this->order($shop, '2026-11-23');
        $cancelled = $this->order($shop, '2026-11-23');
        $alone = User::factory()->create(['role' => UserRole::Customer, 'email' => 'solo@example.test']);
        $only = $this->order($alone, '2026-11-23');
        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();
        $cancelled->forceFill(['status' => OrderStatus::Cancelled])->save();
        $only->forceFill(['status' => OrderStatus::Cancelled])->save();

        foreach ($this->deliveries() as $delivery) {
            (new SendPickupReminder($delivery->id))->handle();
        }
        Mail::assertSent(PickupReminder::class, 1);
        Mail::assertSent(PickupReminder::class, fn (PickupReminder $mail): bool => $mail->hasTo('negozio@example.test') && array_column($mail->snapshot['orders'], 'id') === [$kept->id]);
        $this->assertSame('skipped', collect($this->deliveries())->firstWhere('recipient', 'solo@example.test')->state);
    }

    public function test_reminders_follow_the_mail_switches_and_run_at_the_agreed_times(): void
    {
        $this->travelTo(Carbon::parse('2026-11-22 18:00', 'Europe/Rome'));
        $shop = User::factory()->create(['role' => UserRole::Customer]);
        $this->order($shop, '2026-11-23');
        config(['mail.pickup_reminders_enabled' => false]);
        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();
        config(['mail.pickup_reminders_enabled' => true, 'mail.order_lifecycle_enabled' => false]);
        $this->artisan('orders:queue-pickup-reminders evening')->assertSuccessful();
        $this->assertSame([], $this->deliveries());
        $this->artisan('orders:queue-pickup-reminders tomorrow')->assertFailed();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'orders:queue-pickup-reminders'));
        $this->assertEqualsCanonicalizing(['0 18 * * *', '30 7 * * *'], $events->pluck('expression')->all());
        $this->assertSame(['Europe/Rome'], $events->pluck('timezone')->unique()->values()->all());
    }
}
