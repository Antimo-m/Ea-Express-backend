<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    public function test_simultaneous_receipts_from_two_admins_cannot_exceed_the_order_price(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ea-payment-race-');
        $gate = $database.'.start';
        $workers = [];
        config(['database.default' => 'payment_race', 'database.connections.payment_race' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
        ]]);
        try {
            $this->artisan('migrate', ['--database' => 'payment_race', '--force' => true])->assertSuccessful();
            $admins = User::factory()->count(2)->create(['role' => UserRole::Admin]);
            $order = Order::factory()->create([
                'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'shipping_type' => 'external',
                'price_cents' => 1000, 'quoted_price_cents' => 1000, 'carrier_cost_cents' => 400,
                'pricing_version' => 1, 'price_state' => 'agreed',
            ]);
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'session.driver' => 'array', 'cache.default' => 'array', 'queue.default' => 'sync', 'broadcasting.default' => 'null']);
Illuminate\Support\Facades\DB::purge('sqlite');
$user = App\Models\User::findOrFail($input['user']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$login = Illuminate\Http\Request::create('/login', 'POST', ['email' => $user->email, 'password' => 'password']);
$loginResponse = $kernel->handle($login);
$cookies = [];
foreach ($loginResponse->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
$paused = false;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$paused, $input): void {
    if (! $paused && Illuminate\Support\Facades\DB::transactionLevel() > 0 && str_contains($query->sql, '"orders"') && str_starts_with($query->sql, 'select')) {
        $paused = true;
        touch($input['locked']);
        $deadline = microtime(true) + 5;
        while (! file_exists($input['peer_started']) && microtime(true) < $deadline) {
            usleep(1000);
        }
        usleep(100000);
    }
});
touch($input['ready']);
$deadline = microtime(true) + 10;
while (! file_exists($input['gate']) && microtime(true) < $deadline) {
    usleep(1000);
}
touch($input['started']);
$request = Illuminate\Http\Request::create('/balance/'.$input['order'].'/payment', 'POST', ['action' => 'receive', 'version' => 1, 'received_amount' => '10'], $cookies, [], ['HTTP_ACCEPT' => 'application/json']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
CODE;
            foreach ($admins as $index => $admin) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null',
                    'APP_CONFIG_CACHE' => base_path('bootstrap/cache/config.security.php'),
                ]);
                $worker->setInput(json_encode([
                    'database' => $database, 'ready' => $database.'.ready'.$index, 'gate' => $gate,
                    'locked' => $database.'.locked'.$index, 'started' => $database.'.started'.$index, 'peer_started' => $database.'.started'.(1 - $index),
                    'user' => $admin->id, 'order' => $order->id,
                ], JSON_THROW_ON_ERROR));
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while ((! file_exists($database.'.ready0') || ! file_exists($database.'.ready1')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($database.'.ready0');
            $this->assertFileExists($database.'.ready1');
            touch($gate);
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 422], $statuses, json_encode($results));
            $this->assertFileExists($database.'.locked0');
            $this->assertFileExists($database.'.locked1');
            $this->assertDatabaseCount('payment_entries', 1);
            $this->assertSame(1000, (int) PaymentEntry::sum('amount_cents'));
            $this->assertSame(1000, (int) PaymentEntry::sum('ea_amount_cents'));
            $this->assertNotNull($order->fresh()->paid_at);
            $this->assertSame(2, $order->fresh()->version);
            $this->assertDatabaseCount('economic_audits', 2);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('payment_race');
            foreach ([$database, $gate, $database.'.ready0', $database.'.ready1', $database.'.locked0', $database.'.locked1', $database.'.started0', $database.'.started1', $database.'-wal', $database.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
