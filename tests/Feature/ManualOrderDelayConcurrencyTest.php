<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManualOrderDelayConcurrencyTest extends TestCase
{
    public function test_simultaneous_reports_create_a_single_persistent_signal_and_history_event(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ea-delay-race-');
        $workers = [];
        config(['database.default' => 'delay_race', 'database.connections.delay_race' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
        ]]);

        try {
            $this->artisan('migrate', ['--database' => 'delay_race', '--force' => true])->assertSuccessful();
            $rider = User::factory()->create(['role' => UserRole::Admin]);
            $order = Order::factory()->for($rider, 'rider')->create(['status' => OrderStatus::OutForDelivery]);
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'cache.default' => 'array', 'queue.default' => 'sync', 'broadcasting.default' => 'null']);
Illuminate\Support\Facades\DB::purge('sqlite');
$rider = App\Models\User::findOrFail($input['rider']);
$order = App\Models\Order::findOrFail($input['order']);
$paused = false;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$paused, $input): void {
    if (! $paused && Illuminate\Support\Facades\DB::transactionLevel() > 0 && str_contains($query->sql, '"orders"') && str_starts_with($query->sql, 'select')) {
        $paused = true;
        touch($input['locked']);
        $deadline = microtime(true) + 5;
        while (! file_exists($input['peer_locked']) && microtime(true) < $deadline) {
            usleep(1000);
        }
    }
});
touch($input['ready']);
$deadline = microtime(true) + 10;
while (! file_exists($input['gate']) && microtime(true) < $deadline) {
    usleep(1000);
}
$changed = $app->make(App\Actions\ReportOrderDelay::class)->handle($order, $rider, 1);
echo json_encode(['changed' => $changed], JSON_THROW_ON_ERROR);
CODE;
            foreach ([0, 1] as $index) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null',
                    'APP_CONFIG_CACHE' => base_path('bootstrap/cache/config.security.php'),
                ]);
                $worker->setInput(json_encode([
                    'database' => $database, 'ready' => $database.'.ready'.$index, 'gate' => $database.'.start',
                    'locked' => $database.'.locked'.$index, 'peer_locked' => $database.'.locked'.(1 - $index),
                    'rider' => $rider->id, 'order' => $order->id,
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
            touch($database.'.start');
            $changed = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $changed[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['changed'];
            }
            sort($changed);
            $this->assertSame([false, true], $changed);
            $this->assertFileExists($database.'.locked0');
            $this->assertFileExists($database.'.locked1');
            $this->assertDatabaseCount('order_events', 1);
            $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'delay_action' => 'reported']);
            $this->assertSame(2, $order->fresh()->version);
            $this->assertNotNull($order->fresh()->delay_reported_at);
            $this->assertSame($rider->id, $order->fresh()->delay_reported_by);
            $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('delay_race');
            foreach ([$database, $database.'.start', $database.'.ready0', $database.'.ready1', $database.'.locked0', $database.'.locked1', $database.'-wal', $database.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
