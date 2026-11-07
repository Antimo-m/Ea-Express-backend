<?php

namespace Tests\Feature;

use App\Actions\ManageReceipt;
use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    public static function concurrentActions(): array
    {
        return ['receive' => ['receive', 1, 1000, 1000, 2], 'reverse' => ['reverse', 3, 0, 0, 4], 'restore' => ['restore', 4, 0, 0, 3], 'refund' => ['refund', 3, 0, 0, 0]];
    }

    #[DataProvider('concurrentActions')]
    public function test_simultaneous_accounting_actions_from_two_admins_are_applied_once(string $action, int $entryCount, int $net, int $cash, int $auditCount): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ea-payment-race-');
        $gate = $database.'.start';
        $workers = [];
        $mysqlDatabase = null;
        $mysqlOwner = null;
        try {
            $connection = [...config('database.connections.sqlite'), 'url' => null, 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 5000];
            $source = DB::connection()->getConfig();
            if (($source['driver'] ?? null) === 'mysql') {
                $this->assertContains($source['host'], ['127.0.0.1', 'localhost', '::1']);
                $this->assertMatchesRegularExpression('/\Aea_launch_test_[a-f0-9]{16}\z/', $source['database']);
                $mysqlDatabase = 'ea_launch_test_'.bin2hex(random_bytes(8));
                $mysqlOwner = DB::connection()->getPdo();
                $mysqlOwner->exec('CREATE DATABASE `'.$mysqlDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $connection = [...$source, 'url' => null, 'database' => $mysqlDatabase];
            }
            unset($connection['name']);
            config(['database.default' => 'payment_race', 'database.connections.payment_race' => $connection]);
            $this->artisan('migrate', ['--database' => 'payment_race', '--force' => true])->assertSuccessful();
            $admins = User::factory()->count(2)->create(['role' => UserRole::Admin]);
            $order = Order::factory()->create([
                'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'shipping_type' => 'external',
                'price_cents' => 1000, 'quoted_price_cents' => 1000, 'carrier_cost_cents' => 400,
                'pricing_version' => 1, 'price_state' => 'agreed',
            ]);
            if ($action !== 'receive') {
                app(ManageReceipt::class)->handle($admins->first(), $order, ['action' => 'receive', 'version' => 1, 'received_amount' => '10']);
            }
            if (in_array($action, ['restore', 'refund'])) {
                app(ManageReceipt::class)->handle($admins->first(), $order, ['action' => 'reverse', 'version' => 2, 'note' => 'Storno verificato']);
            }
            $version = $order->fresh()->version;
            $auditsBefore = EconomicAudit::count();
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'payment_race', 'database.connections.payment_race' => $input['connection'], 'session.driver' => 'array', 'cache.default' => 'array', 'queue.default' => 'sync', 'broadcasting.default' => 'null']);
Illuminate\Support\Facades\DB::purge('payment_race');
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
    if (! $paused && Illuminate\Support\Facades\DB::transactionLevel() > 0 && str_contains(str_replace(['"', '`'], '', $query->sql), 'from orders') && str_starts_with($query->sql, 'select')) {
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
$request = Illuminate\Http\Request::create('/balance/'.$input['order'].'/payment', 'POST', $input['payload'], $cookies, [], ['HTTP_ACCEPT' => 'application/json']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
CODE;
            foreach ($admins as $index => $admin) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null',
                    'APP_CONFIG_CACHE' => base_path('bootstrap/cache/config.security.php'),
                ]);
                $worker->setInput(json_encode([
                    'connection' => $connection, 'ready' => $database.'.ready'.$index, 'gate' => $gate,
                    'locked' => $database.'.locked'.$index, 'started' => $database.'.started'.$index, 'peer_started' => $database.'.started'.(1 - $index),
                    'user' => $admin->id, 'order' => $order->slug, 'payload' => ['action' => $action, 'version' => $version, ...($action === 'receive' ? ['received_amount' => '10'] : ['note' => 'Operazione verificata'])],
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
            $this->assertSame($action === 'refund' ? [409, 409] : [200, 422], $statuses, json_encode($results));
            $this->assertFileExists($database.'.locked0');
            $this->assertFileExists($database.'.locked1');
            $this->assertDatabaseCount('payment_entries', $entryCount);
            $this->assertSame($net, (int) PaymentEntry::sum('amount_cents'));
            $this->assertSame($net, (int) PaymentEntry::sum('ea_amount_cents'));
            $this->assertSame($cash, (int) PaymentEntry::sum('cash_amount_cents'));
            $this->assertSame($action === 'receive', $order->fresh()->paid_at !== null);
            $this->assertSame($version + ($action === 'refund' ? 0 : 1), $order->fresh()->version);
            $this->assertDatabaseCount('economic_audits', $auditsBefore + $auditCount);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('payment_race');
            if ($mysqlDatabase !== null && $mysqlOwner !== null) {
                $mysqlOwner->exec('DROP DATABASE `'.$mysqlDatabase.'`');
            }
            foreach ([$database, $gate, $database.'.ready0', $database.'.ready1', $database.'.locked0', $database.'.locked1', $database.'.started0', $database.'.started1', $database.'-wal', $database.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
