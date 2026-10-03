<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\RecipientIncident;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\RecipientRisk;
use App\Support\RiderTracking;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_and_financial_pages_render_realistic_long_content(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Giovanni Battista Esposito']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Centro Distribuzione Elettrodomestici Napoli Nord']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'created_by' => $admin->id, 'rider_id' => $admin->id,
            'store_name' => $customer->name, 'recipient_name' => 'Pierluigi Francesco Di Stefano',
            'pickup_address' => 'Via Giovanni Battista Pergolesi', 'pickup_street_number' => '145',
            'delivery_address' => 'Via Giovanni Battista Pergolesi', 'delivery_street_number' => '145',
            'price_cents' => 12550, 'quoted_price_cents' => 12550, 'pricing_version' => 1, 'price_state' => 'agreed',
            'rate_snapshot' => ['delivery_time' => 'Consegna entro tre giorni lavorativi dalla conferma amministratore, salvo variazioni concordate con il destinatario'],
            'status' => OrderStatus::OutForDelivery,
        ]);
        app(RecipientRisk::class)->record($order, $admin);
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Received, 'note' => 'Ordine creato']);
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Accepted, 'note' => 'Rider assegnato: '.$admin->name]);
        $incoming = Order::factory()->create(['status' => OrderStatus::Received, 'created_by' => $admin->id, 'store_name' => $customer->name, 'recipient_name' => 'Sara', 'pricing_version' => 1, 'price_state' => 'agreed', 'price_cents' => 12550, 'quoted_price_cents' => 12550, 'rate_snapshot' => $order->rate_snapshot]);
        Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'rider_id' => $admin->id, 'store_name' => $customer->name, 'price_cents' => 12550]);
        Order::factory()->count(24)->create(['status' => OrderStatus::OutForDelivery, 'created_by' => $admin->id, 'rider_id' => $admin->id, 'store_name' => 'Centro Distribuzione Elettrodomestici Napoli Nord', 'recipient_name' => 'Pierluigi Francesco Di Stefano', 'price_cents' => 12550]);
        PendingAccount::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'subject' => $customer->name, 'description' => 'In attesa di conferma amministratore per servizi di distribuzione e consegna', 'amount_cents' => 12550]);
        ShippingRate::factory()->create(['delivery_time' => 'Consegna entro tre giorni lavorativi dalla conferma amministratore']);
        $order->messages()->create(['user_id' => $customer->id, 'sender' => 'customer', 'body' => 'Consegna in Via Giovanni Battista Pergolesi 145, Napoli. Citofonare a Pierluigi Di Stefano.']);
        $this->actingAs($admin);
        $paths = ['/dashboard', '/orders/incoming', '/orders/in-progress', '/orders/history', '/orders/'.$order->id, '/orders/'.$incoming->id, '/orders/create', '/tracking', '/pickups', '/rates', '/settings/users', '/balance', '/pending', '/reports', '/stores', '/messages', '/messages/'.$order->id, '/profile', '/settings', '/economic-audits?type=orders&id='.$order->id, '/recipient-incidents', '/recipient-incidents/'.RecipientIncident::sole()->recipient_risk_profile_id];
        $pickupOrder = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::RiderArriving, 'recipient_name' => 'Pierluigi Francesco Di Stefano', 'store_name' => $customer->name]);
        $paths[] = '/orders/'.$pickupOrder->id;
        $operationalRider = User::factory()->create(['name' => 'Pierluigi Francesco Di Stefano Responsabile distribuzione della zona metropolitana']);
        $operationalOrder = null;
        for ($index = 0; $index < 9; $index++) {
            $operationalOrder = Order::factory()->create(['rider_id' => $operationalRider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Zona operativa '.($index + 1).' - Area metropolitana e comuni limitrofi', 'price_cents' => 600]);
        }
        $scaleRiders = User::factory()->count(20)->create();
        for ($index = 0; $index < 100; $index++) {
            Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $scaleRiders[$index % 20]->id, 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Area di prova '.($index % 15), 'price_cents' => 500]);
        }
        $tracking = app(RiderTracking::class);
        $session = $tracking->start($operationalOrder, $operationalRider);
        $tracking->update($operationalOrder, $operationalRider, ['session' => $session, 'latitude' => 40.8518, 'longitude' => 14.2681, 'accuracy' => 12, 'recorded_at' => now()->toIso8601String()]);
        $yesterday = now('Europe/Rome')->subDay()->setTime(10, 30)->utc();
        $historicalOrder = Order::factory()->create(['rider_id' => $operationalRider->id, 'status' => OrderStatus::Delivered, 'delivered_at' => $yesterday, 'delivery_zone' => 'Aversa', 'price_cents' => 500]);
        $event = $historicalOrder->events()->create(['rider_id' => $operationalRider->id, 'operational_zone' => 'Aversa', 'user_id' => $admin->id, 'status' => OrderStatus::Delivered]);
        $event->forceFill(['created_at' => $yesterday])->save();
        $paths[] = '/rider-operations';
        $paths[] = '/rider-operations/'.$operationalRider->id.'?date='.now('Europe/Rome')->subDay()->toDateString();
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if (! $directory) {
            $this->withoutVite();
        }
        foreach ($paths as $path) {
            $response = $this->get($path)->assertOk();
            if ($directory) {
                file_put_contents($directory.'/'.str_replace('/', '_', trim(parse_url($path, PHP_URL_PATH), '/')).'.html', $response->getContent());
            }
        }
        if ($directory) {
            file_put_contents($directory.'/rider-detail.html', $this->get('/rider-operations/'.$operationalRider->id.'?date='.now('Europe/Rome')->subDay()->toDateString())->assertOk()->getContent());
            file_put_contents($directory.'/rider-history.html', $this->get('/rider-operations?date='.now('Europe/Rome')->subDay()->toDateString())->assertOk()->getContent());
            file_put_contents($directory.'/filters-active.html', $this->get('/orders/in-progress?status=out_for_delivery&zone=Zona&shipping_type=regional&urgency=urgent&page=2')->assertOk()->getContent());
            file_put_contents($directory.'/statistics-custom.html', $this->get('/stores?period=custom&from=2026-01-01&to=2026-01-31&status=delivered')->assertOk()->getContent());
            file_put_contents($directory.'/statistics-year.html', $this->get('/stores?period=year&year=2025&status=delivered')->assertOk()->getContent());
            file_put_contents($directory.'/rider-feed.json', $this->getJson('/rider-operations/feed')->assertOk()->getContent());
        }
        $this->app['auth']->forgetGuards();
        foreach (['/' => 'welcome', '/login' => 'login', '/forgot-password' => 'forgot-password'] as $path => $name) {
            $response = $this->get($path)->assertOk();
            if ($directory) {
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
        $this->actingAs($admin);
        $this->getJson('/movements')->assertOk()->assertJsonStructure(['data']);
        $this->get('/orders/in-progress?q='.urlencode($customer->name))->assertSee('Pierluigi Francesco Di Stefano')->assertSee($customer->name);
        $this->get('/orders/'.$order->id)->assertSee('125,50')->assertSee('Consegna entro tre giorni lavorativi');
    }
}
