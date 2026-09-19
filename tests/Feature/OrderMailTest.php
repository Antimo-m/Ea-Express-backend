<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Actions\RecordOrderMail;
use App\Actions\TransitionOrder;
use App\Jobs\SendOrderMail;
use App\Mail\OrderLifecycle;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\CheckoutReview;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_records_one_snapshot_and_repeated_processing_sends_one_mail(): void
    {
        Mail::fake();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $data = Order::factory()->make()->only(['store_name', 'recipient_name', 'recipient_phone', 'pickup_address', 'pickup_city', 'delivery_address', 'delivery_city', 'pickup_date', 'pickup_from', 'pickup_to', 'parcel_count', 'category', 'urgency']);
        $data['pickup_date'] = $data['pickup_date']->toDateString();
        $data = [...$data, 'payment_method' => 'cash', 'content_description' => 'Documenti firmati', 'delivery_window' => '16:30'];
        ShippingRate::factory()->create(['city' => $data['delivery_city'], 'city_key' => ShippingQuote::cityKey($data['delivery_city'])]);
        $review = app(CheckoutReview::class)->preview($customer, $data);
        $order = app(CreateOrder::class)->handle($customer, [...$data, 'checkout_token' => $review['checkout_token']]);
        app(RecordOrderMail::class)->handle($order, 'scheduled');
        $this->assertDatabaseCount('order_mail_deliveries', 1);
        $delivery = DB::table('order_mail_deliveries')->sole();
        $order->update(['content_description' => 'Contenuto successivo']);
        (new SendOrderMail($delivery->id))->handle();
        (new SendOrderMail($delivery->id))->handle();
        Mail::assertSent(OrderLifecycle::class, fn ($mail) => $mail->hasTo($customer->email) && $mail->event === 'scheduled' && str_contains($mail->snapshot['content'], 'Documenti firmati'));
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('order_mail_deliveries', ['id' => $delivery->id, 'state' => 'sent']);
    }

    public function test_delivered_mail_is_distinct_and_template_escapes_customer_content(): void
    {
        Mail::fake();
        $rider = User::factory()->create();
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'contact_email' => 'customer@example.test', 'content_description' => '<script>alert(1)</script>']);
        app(TransitionOrder::class)->handle($order, $rider, ['status' => 'delivered', 'version' => 1]);
        $delivery = DB::table('order_mail_deliveries')->sole();
        $mail = new OrderLifecycle(json_decode($delivery->snapshot, true), 'delivered');
        $mail->assertSeeInHtml('Il tuo ordine è stato consegnato');
        $mail->assertDontSeeInHtml('<script>', false);
        $this->assertStringContainsString('Ordine consegnato', $mail->envelope()->subject);
        (new SendOrderMail($delivery->id))->handle();
        Mail::assertSent(OrderLifecycle::class, fn ($mail) => $mail->event === 'delivered');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_mail_transport_failure_does_not_change_order_and_is_not_blindly_retried(): void
    {
        $order = Order::factory()->create(['contact_email' => 'customer@example.test']);
        app(RecordOrderMail::class)->handle($order, 'scheduled');
        $id = DB::table('order_mail_deliveries')->value('id');
        $this->app->instance('mailer', app('mailer'));
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        (new SendOrderMail($id))->handle();
        (new SendOrderMail($id))->handle();
        $this->assertDatabaseHas('order_mail_deliveries', ['id' => $id, 'state' => 'uncertain', 'error' => 'RuntimeException']);
        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
    }

    public function test_rollback_discards_email_and_disabled_dispatch_does_not_send(): void
    {
        $order = Order::factory()->create(['contact_email' => 'customer@example.test']);
        DB::beginTransaction();
        app(RecordOrderMail::class)->handle($order, 'scheduled');
        DB::rollBack();
        $this->assertDatabaseCount('order_mail_deliveries', 0);
        Mail::fake();
        app(RecordOrderMail::class)->handle($order, 'scheduled');
        config(['mail.order_lifecycle_enabled' => false]);
        $this->artisan('orders:dispatch-mail')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertDatabaseHas('order_mail_deliveries', ['order_id' => $order->id, 'state' => 'pending']);
    }
}
