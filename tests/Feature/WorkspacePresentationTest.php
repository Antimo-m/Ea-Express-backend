<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\RecipientIncident;
use App\Models\RecipientRiskProfile;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_and_financial_pages_render_realistic_long_content(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Amministratore Test']);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Azienda Test Distribuzione Metropolitana']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'created_by' => $admin->id, 'rider_id' => $admin->id,
            'store_name' => $customer->name, 'recipient_name' => 'Destinatario Test Nominativo Esteso',
            'pickup_address' => 'Via Test Dimostrativa del Punto di Ritiro', 'pickup_street_number' => '145',
            'delivery_address' => 'Via Test Dimostrativa del Punto di Ritiro', 'delivery_street_number' => '145',
            'price_cents' => 12550, 'quoted_price_cents' => 12550, 'pricing_version' => 1, 'price_state' => 'agreed',
            'rate_snapshot' => ['delivery_time' => 'Consegna entro tre giorni lavorativi dalla conferma amministratore, salvo variazioni concordate con il destinatario'],
            'status' => OrderStatus::OutForDelivery,
        ]);
        app(RecipientRisk::class)->record($order, $admin);
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Received, 'note' => 'Ordine creato']);
        $order->events()->create(['user_id' => $admin->id, 'status' => OrderStatus::Accepted, 'note' => 'Rider assegnato: '.$admin->name]);
        $incoming = Order::factory()->create(['status' => OrderStatus::Received, 'created_by' => $admin->id, 'store_name' => $customer->name, 'recipient_name' => 'Destinatario Test A', 'pricing_version' => 1, 'price_state' => 'agreed', 'price_cents' => 12550, 'quoted_price_cents' => 12550, 'rate_snapshot' => $order->rate_snapshot]);
        Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'rider_id' => $admin->id, 'store_name' => $customer->name, 'price_cents' => 12550]);
        Order::factory()->count(24)->create(['status' => OrderStatus::OutForDelivery, 'created_by' => $admin->id, 'rider_id' => $admin->id, 'store_name' => 'Azienda Test Distribuzione Metropolitana', 'recipient_name' => 'Destinatario Test Nominativo Esteso', 'price_cents' => 12550]);
        PendingAccount::factory()->create(['created_by' => $admin->id, 'customer_id' => $customer->id, 'subject' => $customer->name, 'description' => 'In attesa di conferma amministratore per servizi di distribuzione e consegna', 'amount_cents' => 12550]);
        ShippingRate::factory()->create(['delivery_time' => 'Consegna entro tre giorni lavorativi dalla conferma amministratore']);
        $order->messages()->create(['user_id' => $customer->id, 'sender' => 'customer', 'body' => 'Consegna di prova in Via Test Dimostrativa 145, Napoli. Citofonare al Destinatario Test.']);
        $this->actingAs($admin);
        $paths = ['/dashboard', '/orders/incoming', '/orders/in-progress', '/orders/history', '/orders/'.$order->slug, '/orders/'.$incoming->slug, '/orders/create', '/pickups', '/rates', '/settings/users', '/balance', '/pending', '/reports', '/customers', '/customers/'.$customer->slug, '/messages', '/messages/'.$order->slug, '/profile', '/settings', '/economic-audits?type=orders&slug='.$order->slug, '/recipient-incidents', '/recipient-incidents/'.RecipientIncident::sole()->profile->slug];
        $pickupOrder = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::RiderArriving, 'recipient_name' => 'Destinatario Test Nominativo Esteso', 'store_name' => $customer->name]);
        $paths[] = '/orders/'.$pickupOrder->slug;
        $operationalRider = User::factory()->create(['name' => 'Rider Test Nominativo Esteso Responsabile distribuzione della zona dimostrativa']);
        $operationalOrder = null;
        for ($index = 0; $index < 9; $index++) {
            $operationalOrder = Order::factory()->create(['rider_id' => $operationalRider->id, 'pickup_date' => now('Europe/Rome')->toDateString(), 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Zona operativa '.($index + 1).' - Area metropolitana e comuni limitrofi', 'price_cents' => 600]);
        }
        $scaleRiders = User::factory()->count(20)->create();
        for ($index = 0; $index < 100; $index++) {
            Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $scaleRiders[$index % 20]->id, 'pickup_date' => now('Europe/Rome')->toDateString(), 'status' => OrderStatus::OutForDelivery, 'delivery_zone' => 'Area di prova '.($index % 15), 'price_cents' => 500]);
        }
        $yesterday = now('Europe/Rome')->subDay()->setTime(10, 30)->utc();
        $historicalOrder = Order::factory()->create(['rider_id' => $operationalRider->id, 'pickup_date' => now('Europe/Rome')->subDay()->toDateString(), 'status' => OrderStatus::Delivered, 'delivered_at' => $yesterday, 'delivery_zone' => 'Aversa', 'price_cents' => 500]);
        $event = $historicalOrder->events()->create(['rider_id' => $operationalRider->id, 'operational_zone' => 'Aversa', 'user_id' => $admin->id, 'status' => OrderStatus::Delivered]);
        $event->forceFill(['created_at' => $yesterday])->save();
        $paths[] = '/rider-operations';
        $paths[] = '/rider-operations/'.$operationalRider->slug.'?date='.now('Europe/Rome')->subDay()->toDateString();
        $directory = getenv('EA_UI_FIXTURE_DIR');
        if (! $directory) {
            $this->withoutVite();
        }
        foreach ($paths as $path) {
            $response = $this->get($path)->assertOk();
            if ($directory) {
                file_put_contents($directory.'/'.str_replace('/', '_', trim(parse_url($path, PHP_URL_PATH), '/')).'.html', $response->getContent());
                $canonical = preg_replace_callback('#/(orders|messages|recipient-incidents|rider-operations)/([0-9a-f-]{36})#', function ($match): string {
                    $model = match ($match[1]) {
                        'orders', 'messages' => Order::class, 'recipient-incidents' => RecipientRiskProfile::class, 'rider-operations' => User::class
                    };

                    return '/'.$match[1].'/'.$model::where('slug', $match[2])->value('id');
                }, $path);
                file_put_contents($directory.'/'.str_replace('/', '_', trim(parse_url($canonical, PHP_URL_PATH), '/')).'.html', $response->getContent());
            }
        }
        if ($directory) {
            file_put_contents($directory.'/customer-detail.html', $this->get('/customers/'.$customer->slug)->assertOk()->getContent());
            file_put_contents($directory.'/rider-detail.html', $this->get('/rider-operations/'.$operationalRider->slug.'?date='.now('Europe/Rome')->subDay()->toDateString())->assertOk()->getContent());
            file_put_contents($directory.'/rider-history.html', $this->get('/rider-operations?date='.now('Europe/Rome')->subDay()->toDateString())->assertOk()->getContent());
            file_put_contents($directory.'/filters-active.html', $this->followingRedirects()->get('/orders/in-progress?status=out_for_delivery&zone=Zona&shipping_type=regional&urgency=urgent&page=2')->assertOk()->getContent());
            file_put_contents($directory.'/customer-custom.html', $this->get('/customers/'.$customer->slug.'?period=custom&from=2026-01-01&to=2026-01-31&status=delivered')->assertOk()->getContent());
            file_put_contents($directory.'/customer-year.html', $this->get('/customers/'.$customer->slug.'?period=year&year=2025&status=delivered')->assertOk()->getContent());
            file_put_contents($directory.'/rider-orders.html', $this->get('/rider-operations/detail?date='.now('Europe/Rome')->toDateString())->assertOk()->getContent());
            file_put_contents($directory.'/rider-feed.json', $this->getJson('/rider-operations/feed')->assertOk()->getContent());
        }
        $this->app['auth']->forgetGuards();
        foreach (['/' => 'welcome', '/login' => 'login'] as $path => $name) {
            $response = $this->get($path)->assertOk();
            if ($directory) {
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
        $this->actingAs($admin);
        $this->getJson('/movements')->assertOk()->assertJsonStructure(['data']);
        $this->get('/orders/in-progress?q='.urlencode($customer->name))->assertSee('Destinatario Test Nominativo Esteso')->assertSee($customer->name);
        $this->get('/orders/'.$order->slug)->assertSee('125,50')->assertSee('Consegna entro tre giorni lavorativi');
    }
}
