<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionRevocationConcurrencyTest extends TestCase
{
    public static function databases(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql']];
    }

    #[DataProvider('databases')]
    public function test_concurrent_revocations_increment_security_version_without_lost_updates(string $driver): void
    {
        if ($driver === 'mysql' && getenv('EA_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Richiede EA_TEST_MYSQL=1 e accesso al MySQL locale con creazione di database temporanei.');
        }
        $directory = sys_get_temp_dir().'/ea-session-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/race.sqlite';
        touch($database);
        $workers = [];
        $owner = null;
        $mysqlDatabase = null;
        $original = config('database.default');
        try {
            if ($driver === 'mysql') {
                $source = config('database.connections.mysql');
                $this->assertContains($source['host'], ['127.0.0.1', 'localhost', '::1']);
                config(['database.connections.admin_owner' => [...$source, 'url' => null, 'database' => null]]);
                $owner = DB::connection('admin_owner')->getPdo();
                $mysqlDatabase = 'ea_launch_test_'.bin2hex(random_bytes(8));
                $owner->exec('CREATE DATABASE `'.$mysqlDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $connection = [...$source, 'url' => null, 'database' => $mysqlDatabase];
            } else {
                $connection = [...config('database.connections.sqlite'), 'url' => null, 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 5000];
            }
            config(['database.default' => 'admin_race', 'database.connections.admin_race' => $connection]);
            $this->artisan('migrate', ['--database' => 'admin_race', '--force' => true])->assertSuccessful();
            $account = User::factory()->create();
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'admin_race', 'database.connections.admin_race' => $input['connection']]);
Illuminate\Support\Facades\DB::purge('admin_race');
touch($input['ready']);
$deadline = microtime(true) + 10;
while (! file_exists($input['start']) && microtime(true) < $deadline) { usleep(1000); }
$user = App\Models\User::findOrFail($input['user_id']);
app(App\Support\AccountSessionRevocation::class)->revoke($user);
echo 'revoked';
CODE;
            for ($index = 0; $index < 4; $index++) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php', 'SESSION_DRIVER' => 'array']);
                $worker->setInput(json_encode(['connection' => $connection, 'user_id' => $account->id, 'ready' => $directory.'/ready'.$index, 'start' => $directory.'/start'], JSON_THROW_ON_ERROR));
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready*')) < 4 && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertCount(4, glob($directory.'/ready*'));
            touch($directory.'/start');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = trim($worker->getOutput());
            }
            $this->assertSame(['revoked', 'revoked', 'revoked', 'revoked'], $results);
            $this->assertSame(4, (int) $account->fresh()->auth_version);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('admin_race');
            if ($owner && $mysqlDatabase) {
                $owner->exec('DROP DATABASE `'.$mysqlDatabase.'`');
            }
            DB::purge('admin_owner');
            config(['database.default' => $original]);
            File::deleteDirectory($directory);
        }
    }
}
