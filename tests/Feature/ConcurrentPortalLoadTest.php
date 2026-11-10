<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentPortalLoadTest extends TestCase
{
    public function test_forty_customers_and_five_riders_can_work_concurrently_over_http(): void
    {
        if (getenv('EA_RUN_CONCURRENT_LOAD') !== '1') {
            $this->markTestSkipped('Profilo HTTP/MySQL controllato: EA_RUN_CONCURRENT_LOAD=1.');
        }
        $this->assertTrue(function_exists('posix_setsid') && function_exists('pcntl_exec'));
        $directory = sys_get_temp_dir().'/ea-concurrent-load-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/otp', 0700);
        $source = DB::connection('mysql')->getConfig();
        $this->assertContains($source['host'], ['127.0.0.1', 'localhost', '::1']);
        $owner = new PDO('mysql:host='.$source['host'].';port='.$source['port'].';charset=utf8mb4', $source['username'], $source['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($source['options'] ?? []));
        $database = 'ea_launch_test_'.bin2hex(random_bytes(8));
        $owner->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server = null;
        $clients = null;
        $serverGroup = null;
        $result = null;
        try {
            unset($source['name']);
            config(['database.default' => 'concurrent_load', 'database.connections.concurrent_load' => [...$source, 'url' => null, 'database' => $database]]);
            $this->artisan('migrate', ['--database' => 'concurrent_load', '--force' => true])->assertSuccessful();
            /** Keep the factory's shared default hash at the normal test cost. */
            User::factory()->make();
            config(['hashing.bcrypt.rounds' => 12]);
            Hash::forgetDrivers();
            $password = bin2hex(random_bytes(16));
            $users = $this->fixtures(Hash::make($password, ['rounds' => 12]));
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            $this->assertIsResource($socket, $errorMessage);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $base = 'http://'.$address;
            $router = $directory.'/router.php';
            file_put_contents($router, $this->router());
            $environment = [
                'APP_ENV' => 'loadtest', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_URL' => $base, 'APP_CONFIG_CACHE' => $directory.'/config.php',
                'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $source['host'],
                'DB_PORT' => (string) $source['port'], 'DB_DATABASE' => $database,
                'DB_USERNAME' => (string) $source['username'], 'DB_PASSWORD' => (string) $source['password'],
                'DB_SOCKET' => (string) ($source['unix_socket'] ?? ''),
                'CACHE_STORE' => 'database', 'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_LOCK_CONNECTION' => 'mysql',
                'AUTH_SECURITY_STORE' => 'database',
                'CACHE_PREFIX' => $database, 'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql',
                'SESSION_COOKIE' => 'ea_load_session', 'SESSION_SECURE_COOKIE' => 'false',
                'SESSION_DOMAIN' => 'null', 'SESSION_SAME_SITE' => 'lax', 'SESSION_ENCRYPT' => 'false',
                'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync',
                'AUTH_OTP_MAILER' => 'smtp',
                'LOG_CHANNEL' => 'errorlog', 'LOG_LEVEL' => 'warning', 'ORDER_MAIL_ENABLED' => 'false',
                'CUSTOMER_FRONTEND_URL' => $base, 'CUSTOMER_ALLOWED_ORIGINS' => $base,
                'PHP_CLI_SERVER_WORKERS' => '8', 'XDEBUG_MODE' => 'off', 'BCRYPT_ROUNDS' => '12',
                'EA_LOAD_DIRECTORY' => $directory, 'EA_LOAD_BASE_PATH' => base_path(),
            ];
            $server = new Process([
                PHP_BINARY, '-r', 'if (posix_setsid() < 0) { exit(70); } pcntl_exec(PHP_BINARY, array_slice($argv, 1));', '--',
                '-d', 'memory_limit=256M', '-d', 'opcache.enable_cli=1', '-S', $address, '-t', public_path(), $router,
            ], base_path(), $environment);
            $server->disableOutput()->setTimeout(180)->start();
            $serverGroup = $server->getPid();
            $ready = false;
            for ($attempt = 0; $attempt < 200; $attempt++) {
                $probe = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                if (! $server->isRunning()) {
                    break;
                }
                usleep(50_000);
            }
            $this->assertTrue($ready, is_file($directory.'/laravel.log') ? file_get_contents($directory.'/laravel.log') : 'Il server HTTP isolato non è partito.');
            $this->assertSame($serverGroup, posix_getpgid($serverGroup));
            $clients = new Process(['node', base_path('tests/concurrent-portal.load.mjs')], base_path());
            $clients->setInput(json_encode([
                'base' => $base, 'directory' => $directory, 'users' => $users, 'password' => $password,
                'baseline_seconds' => (int) (getenv('EA_LOAD_SECONDS') ?: 30), 'pressure_seconds' => 5, 'think_ms' => 500,
            ], JSON_THROW_ON_ERROR));
            $clients->setTimeout(150)->run();
            $this->assertTrue($clients->isSuccessful(), $clients->getErrorOutput());
            $result = json_decode($clients->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $result['metadata'] = [
                'php' => PHP_VERSION, 'mysql' => $owner->query('SELECT VERSION()')->fetchColumn(),
                'laravel' => app()->version(), 'customers' => 40, 'riders' => 5,
                'historical_orders' => 20000, 'active_orders' => 50, 'bcrypt_rounds' => 12,
                'server' => 'PHP built-in HTTP server, 8 child workers plus master; CLI OPcache enabled',
                'transport' => 'Loopback HTTP; distinct test IPs assigned by temporary router; same-IP control included',
                'storage' => 'Isolated MySQL; database sessions and rate-limit cache',
                'excluded' => ['FPM', 'TLS and WAN latency', 'WebSocket delivery', 'SMTP delivery', 'production hosting'],
            ];
            $this->assertSame([], $result['failures'], json_encode($result['failures'], JSON_THROW_ON_ERROR));
            $this->assertSame(45, $result['authenticated_users']);
            $this->assertSame(5, User::where('role', UserRole::Rider)->whereNotNull('email_verified_at')->count());
            $this->assertSame(1, OrderMessage::where('submission_key', $result['duplicate_submission_key'])->count());
            $this->assertDatabaseCount('orders', 20050);
            $this->assertSame(0, DB::table('order_events')->select('order_id', 'status')->groupBy('order_id', 'status')->havingRaw('COUNT(*) > 1')->get()->count());
            $this->assertGreaterThan(0, DB::table('notifications')->count());
            $result['integrity'] = ['order_count' => Order::count(), 'message_count' => OrderMessage::count(), 'notification_count' => DB::table('notifications')->count(), 'transition_events' => DB::table('order_events')->count(), 'duplicate_transition_groups' => 0, 'duplicate_submission_rows' => 1];
        } finally {
            if ($clients?->isRunning()) {
                $clients->stop();
            }
            if ($serverGroup) {
                @posix_kill(-$serverGroup, SIGTERM);
            }
            if ($server?->isRunning()) {
                $server->stop(3);
            }
            $target = getenv('EA_LOAD_CAPTURE_DIR') ?: $directory.'/results';
            File::ensureDirectoryExists($target, 0700);
            if ($server) {
                file_put_contents($target.'/server.log', is_file($directory.'/laravel.log') ? file_get_contents($directory.'/laravel.log') : '');
            }
            if ($clients) {
                file_put_contents($target.'/client.log', $clients->getErrorOutput());
            }
            DB::purge('concurrent_load');
            $owner->exec('DROP DATABASE `'.$database.'`');
            if ($result !== null) {
                $result['metadata']['temporary_database_removed'] = true;
                file_put_contents($target.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            File::deleteDirectory($directory.'/otp');
            @unlink($directory.'/router.php');
            if (getenv('EA_LOAD_CAPTURE_DIR')) {
                File::deleteDirectory($directory);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function fixtures(string $hash): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'password' => $hash]);
        $customers = User::factory()->count(40)->create(['role' => UserRole::Customer, 'password' => $hash]);
        $riders = User::factory()->unverified()->count(5)->create(['password' => $hash]);
        ShippingRate::factory()->create(['city' => 'Caserta', 'city_key' => 'caserta', 'postal_code' => '81100', 'active' => true]);
        $users = [];
        foreach ($customers as $index => $customer) {
            $rows = Order::factory()->count(500)->make([
                'created_by' => $admin->id, 'customer_id' => $customer->id,
                'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'pickup_date' => now('Europe/Rome')->toDateString(),
                'price_cents' => 500, 'parcel_value_cents' => 10000, 'created_at' => now(), 'updated_at' => now(),
            ])->map(fn (Order $order): array => $order->getAttributes())->all();
            Order::insert($rows);
            $users[] = ['role' => 'customer', 'email' => $customer->email, 'ip' => '198.51.100.'.($index + 1), 'orders' => [$rows[0]['slug']]];
        }
        foreach ($riders as $index => $rider) {
            $orders = collect();
            for ($position = 0; $position < 10; $position++) {
                $customerIndex = ($index * 10 + $position) % 40;
                $order = Order::factory()->create([
                    'created_by' => $admin->id, 'customer_id' => $customers[$customerIndex]->id, 'rider_id' => $rider->id,
                    'status' => OrderStatus::Accepted, 'pickup_date' => now('Europe/Rome')->toDateString(), 'price_cents' => 500,
                    'quoted_price_cents' => 500, 'price_state' => 'agreed', 'pricing_version' => 1,
                ]);
                $users[$customerIndex]['orders'] = [$order->slug];
                $orders->push($order);
            }
            $users[] = ['role' => 'rider', 'email' => $rider->email, 'ip' => '198.51.100.'.($index + 41), 'orders' => $orders->pluck('slug')->all()];
        }

        return $users;
    }

    private function router(): string
    {
        return <<<'PHP'
<?php
$base = getenv('EA_LOAD_BASE_PATH');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($base.'/public'.$path)) {
    return false;
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default' => 'single', 'logging.channels.single.path' => getenv('EA_LOAD_DIRECTORY').'/laravel.log']);
Illuminate\Support\Facades\Event::listen(Illuminate\Notifications\Events\NotificationSending::class, function (Illuminate\Notifications\Events\NotificationSending $event): ?bool {
    if ($event->notification instanceof App\Notifications\RiderEmailOtp) {
        $file = getenv('EA_LOAD_DIRECTORY').'/otp/'.hash('sha256', $event->notifiable->email).'.json';
        file_put_contents($file, json_encode(['code' => $event->notification->code], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($file, 0600);

        return false;
    }

    return null;
});
$request = Illuminate\Http\Request::capture();
$ip = $request->headers->get('X-EA-Load-IP', '127.0.0.1');
if (preg_match('/\A(?:198\.51\.100|203\.0\.113)\.[0-9]{1,3}\z/', $ip)) {
    $request->server->set('REMOTE_ADDR', $ip);
}
$app->handleRequest($request);
PHP;
    }
}
