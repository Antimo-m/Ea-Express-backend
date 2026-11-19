<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RecipientIncident;
use App\Models\User;
use App\OrderStatus;
use App\Support\RecipientIdentity;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipientRiskTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(): array
    {
        return ['recipient_name' => 'Destinatario Test Uno', 'recipient_phone' => '+390100000000', 'delivery_address' => 'Via Test', 'delivery_street_number' => '50', 'delivery_postal_code' => '80100', 'delivery_city' => 'Napoli', 'delivery_province' => 'NA'];
    }

    private function failedOrder(User $rider, array $changes = []): Order
    {
        return Order::factory()->create([...$this->recipient(), 'rider_id' => $rider->id, 'status' => OrderStatus::DeliveryAttempted, ...$changes]);
    }

    private function report(User $actor, Order $order): void
    {
        $this->actingAs($actor, 'web')->patch(route('orders.update', $order), ['status' => 'cancelled', 'version' => $order->fresh()->version, 'note' => 'Destinatario assente, pacco da restituire', 'cancellation_reason' => 'recipient_absent'])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_recipient_absent_report_creates_one_incident_and_warns_without_blocking_next_order(): void
    {
        $rider = User::factory()->create();
        $order = $this->failedOrder($rider, ['status' => OrderStatus::OutForDelivery]);
        $this->actingAs($rider)->patch(route('orders.update', $order), ['status' => 'delivery_attempted', 'version' => 1, 'note' => 'Destinatario assente'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('recipient_incidents', 1);
        $this->report($rider, $order);
        $this->assertDatabaseCount('recipient_incidents', 1);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseHas('recipient_incidents', ['order_id' => $order->id, 'recorded_by' => $rider->id, 'reason' => 'recipient_absent']);
        $this->patchJson(route('orders.update', $order), ['status' => 'cancelled', 'version' => 1, 'note' => 'Ripetuto', 'cancellation_reason' => 'recipient_absent'])->assertUnprocessable();
        $this->assertDatabaseCount('recipient_incidents', 1);
        $next = Order::factory()->create([...$this->recipient(), 'status' => OrderStatus::OutForDelivery, 'rider_id' => $rider->id]);
        $this->get(route('orders.show', $next))->assertOk()->assertSee('NON AFFIDABILE')->assertSee('Ultimo episodio:');
        $this->patch(route('orders.update', $next), ['status' => 'delivered', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Delivered, $next->fresh()->status);
        $this->assertDatabaseHas('economic_audits', ['action' => 'recipient_incident.created']);
    }

    public function test_generic_cancellation_and_failed_pickup_do_not_report_recipient(): void
    {
        $rider = User::factory()->create();
        $order = $this->failedOrder($rider);
        $this->actingAs($rider)->patch(route('orders.update', $order), ['status' => 'cancelled', 'version' => 1, 'note' => 'Richiesta del mittente', 'cancellation_reason' => 'other'])->assertSessionHasNoErrors();
        $pickup = $this->failedOrder($rider, ['status' => OrderStatus::RiderArriving]);
        $this->patchJson(route('orders.update', $pickup), ['status' => 'cancelled', 'version' => 1, 'note' => 'Ritiro impossibile', 'cancellation_reason' => 'recipient_absent'])->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason');
        $this->assertDatabaseCount('recipient_incidents', 0);
        $this->assertSame(OrderStatus::RiderArriving, $pickup->fresh()->status);
        $this->get(route('orders.show', $pickup))->assertOk()->assertSee('AFFIDABILE')->assertSee('Nessun precedente registrato.');
    }

    public function test_normalized_phone_and_address_match_but_namesakes_do_not(): void
    {
        $rider = User::factory()->create();
        $this->report($rider, $this->failedOrder($rider));
        $phone = Order::factory()->make([...$this->recipient(), 'id' => 100, 'recipient_name' => ' DESTINATARIO   TEST UNO ', 'recipient_phone' => '00390100000000', 'delivery_address' => 'Via Test Alternativa']);
        $localPhone = Order::factory()->make([...$this->recipient(), 'id' => 101, 'recipient_phone' => '0100000000', 'delivery_address' => 'Via Test Alternativa']);
        $address = Order::factory()->make([...$this->recipient(), 'id' => 102, 'recipient_phone' => '+390000000001', 'delivery_address' => 'via TEST, 50', 'delivery_city' => ' NAPOLI ']);
        $namesake = Order::factory()->make([...$this->recipient(), 'id' => 103, 'recipient_phone' => '+390000000001', 'delivery_address' => 'Via Test Diversa', 'delivery_street_number' => '12']);
        $sharedPhone = Order::factory()->make([...$this->recipient(), 'id' => 104, 'recipient_name' => 'Destinatario Test Due']);
        $risks = app(RecipientRisk::class)->forOrders(collect([$phone, $localPhone, $address, $namesake, $sharedPhone]));
        $this->assertSame(1, $risks[100]['count']);
        $this->assertSame(1, $risks[101]['count']);
        $this->assertSame(1, $risks[102]['count']);
        $this->assertSame(0, $risks[103]['count']);
        $this->assertSame(0, $risks[104]['count']);
        $this->assertSame(['phone_key' => null, 'address_key' => null], RecipientIdentity::keys(['recipient_name' => 'Destinatario Test Uno']));
    }

    public function test_repeated_incidents_share_profile_and_list_matching_uses_one_query(): void
    {
        $rider = User::factory()->create();
        $this->report($rider, $this->failedOrder($rider));
        $this->report($rider, $this->failedOrder($rider, ['recipient_phone' => '0100000000']));
        $this->assertDatabaseCount('recipient_risk_profiles', 1);
        $this->assertDatabaseCount('recipient_incidents', 2);
        $orders = Order::factory()->count(15)->create($this->recipient());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $risks = app(RecipientRisk::class)->forOrders($orders);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertSame(2, $risks[$orders->first()->id]['count']);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/recipient-incidents')->assertOk()->assertViewHas('activeIncidents', 2);
    }

    public function test_admin_can_correct_and_dismiss_incident_without_changing_original_order(): void
    {
        $rider = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = $this->failedOrder($rider);
        $this->report($rider, $order);
        $incident = RecipientIncident::sole();
        $this->actingAs($admin)->patch('/recipient-incidents/'.$incident->slug, [...$this->recipient(), 'recipient_name' => 'Destinatario Test Due', 'action' => 'correct', 'version' => 1, 'correction_reason' => 'Nome registrato per errore'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Destinatario Test Uno', $order->fresh()->recipient_name);
        $this->assertSame('Destinatario Test Due', $incident->fresh()->recipient['recipient_name']);
        $this->assertSame(0, app(RecipientRisk::class)->forOrders(collect([$order]))[$order->id]['count']);
        $corrected = Order::factory()->create([...$this->recipient(), 'recipient_name' => 'Destinatario Test Due']);
        $this->assertSame(1, app(RecipientRisk::class)->forOrders(collect([$corrected]))[$corrected->id]['count']);
        $this->patch('/recipient-incidents/'.$incident->slug, ['action' => 'dismiss', 'version' => 2, 'correction_reason' => 'Segnalazione non confermata'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, app(RecipientRisk::class)->forOrders(collect([$corrected]))[$corrected->id]['count']);
        $this->assertNotNull($incident->fresh()->dismissed_at);
        $this->assertDatabaseCount('recipient_incidents', 1);
        $this->assertDatabaseHas('economic_audits', ['action' => 'recipient_incident.dismiss']);
        $this->get('/economic-audits?type=recipient_incidents&slug='.$incident->slug)->assertOk()->assertSee('Segnalazione destinatario rimossa')->assertSee('Nome registrato per errore');
        $this->patchJson('/recipient-incidents/'.$incident->slug, ['action' => 'dismiss', 'version' => 2, 'correction_reason' => 'Ripetizione'])->assertConflict();
    }

    public function test_riders_see_only_assigned_order_badges_and_customers_never_receive_risk_data(): void
    {
        $rider = User::factory()->create();
        $otherRider = User::factory()->create();
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = $this->failedOrder($rider, ['customer_id' => $customer->id]);
        $this->report($rider, $order);
        $incident = RecipientIncident::sole();
        $this->get('/recipient-incidents')->assertForbidden();
        $this->patch('/recipient-incidents/'.$incident->slug, ['action' => 'dismiss', 'version' => 1, 'correction_reason' => 'Non consentito'])->assertForbidden();
        $this->actingAs($otherRider)->get(route('orders.show', $order))->assertNotFound();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/orders/'.$order->slug)->assertOk()->assertJsonMissingPath('data.recipientRisk')->assertJsonMissingPath('data.recipient_incidents')->assertDontSee('NON AFFIDABILE')->assertDontSee('recipient_absent');
        $this->actingAs($customer, 'web')->get('/recipient-incidents')->assertForbidden();
        $this->patch('/recipient-incidents/'.$incident->slug, ['action' => 'dismiss', 'version' => 1, 'correction_reason' => 'Non consentito'])->assertForbidden();
        $this->assertNull($incident->fresh()->dismissed_at);
    }
}
