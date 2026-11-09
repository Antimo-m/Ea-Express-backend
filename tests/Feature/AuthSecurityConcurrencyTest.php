<?php

namespace Tests\Feature;

use App\Support\AuthSecurityStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuthSecurityConcurrencyTest extends TestCase
{
    public static function counters(): array
    {
        return ['password failures' => ['password'], 'IP requests' => ['ip']];
    }

    #[DataProvider('counters')]
    public function test_parallel_requests_cannot_bypass_limits_or_lose_increments(string $mode): void
    {
        $directory = sys_get_temp_dir().'/ea-auth-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/cache', 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $workers = [];
        config(['database.default' => 'auth_race', 'database.connections.auth_race' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]]);
        config(['auth_security.store' => 'auth_race_cache', 'cache.stores.auth_race_cache' => ['driver' => 'database', 'connection' => 'auth_race', 'table' => 'cache', 'lock_table' => 'cache_locks']]);
        try {
            $this->artisan('migrate', ['--database' => 'auth_race', '--force' => true])->assertSuccessful();
            $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'session.driver' => 'array', 'cache.default' => 'database', 'auth_security.store' => 'database', 'cache.stores.database.connection' => 'sqlite', 'cache.stores.database.lock_connection' => 'sqlite', 'cache.stores.database.table' => 'cache', 'cache.stores.database.lock_table' => 'cache_locks', 'hashing.bcrypt.rounds' => 4]);
Illuminate\Support\Facades\DB::purge('sqlite');
$session = app('session.store');
$session->start();
$request = Illuminate\Http\Request::create('/login', 'POST', ['email' => 'parallel@example.test', 'password' => 'wrong'], server: ['HTTP_ACCEPT' => 'application/json']);
$request->setLaravelSession($session);
$app->instance('request', $request);
touch($input['directory'].'/ready'.$input['index']);
$deadline = microtime(true) + 10;
while (! file_exists($input['directory'].'/start') && microtime(true) < $deadline) { usleep(1000); }
try {
    if ($input['mode'] === 'ip') {
        app(App\Support\AuthSecurityStore::class)->consume('auth:concurrency:ip', 3, 600);
        echo 'failed_password';
        exit;
    }
    app(App\Support\AuthProtection::class)->attempt($request, 'web', ['email' => 'parallel@example.test', 'password' => 'wrong', 'role' => ['admin', 'rider']]);
    echo 'unexpected';
} catch (Illuminate\Validation\ValidationException) {
    echo 'failed_password';
} catch (Illuminate\Http\Exceptions\ThrottleRequestsException) {
    echo 'limited';
}
PHP;
            for ($index = 0; $index < 8; $index++) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php', 'BROADCAST_CONNECTION' => 'null']);
                $worker->setInput(json_encode(['database' => $database, 'directory' => $directory, 'index' => $index, 'mode' => $mode], JSON_THROW_ON_ERROR));
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = count(glob($directory.'/ready*'));
                if ($ready < 8) {
                    usleep(1000);
                }
            } while ($ready < 8 && microtime(true) < $deadline);
            $this->assertSame(8, $ready);
            touch($directory.'/start');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $result = trim($worker->getOutput());
                $this->assertContains($result, ['failed_password', 'limited']);
                $results[] = $result;
            }
            $failures = count(array_filter($results, fn (string $result): bool => $result === 'failed_password'));
            $this->assertGreaterThanOrEqual(1, $failures);
            $this->assertLessThanOrEqual(3, $failures);
            if ($mode === 'ip') {
                $this->assertSame($failures, app(AuthSecurityStore::class)->limiter()->attempts('auth:concurrency:ip'));
            } else {
                $state = Cache::store('auth_race_cache')->get(AuthSecurityStore::key('account', 'parallel@example.test'));
                $this->assertCount($failures, $state['failures']);
            }
            $this->assertDatabaseCount('users', 0);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('auth_race');
            File::deleteDirectory($directory);
        }
    }
}
