<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdminUniquenessConcurrencyTest extends TestCase
{
    public static function databases(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql']];
    }

    #[DataProvider('databases')]
    public function test_database_allows_only_one_of_two_simultaneous_admin_creations(string $driver): void
    {
        if ($driver === 'mysql' && getenv('EA_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Richiede EA_TEST_MYSQL=1 e accesso al MySQL locale con creazione di database temporanei.');
        }
        $directory = sys_get_temp_dir().'/ea-admin-race-'.bin2hex(random_bytes(8));
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
try {
    Illuminate\Support\Facades\DB::table('users')->insert($input['user']);
    echo 'created';
} catch (Illuminate\Database\UniqueConstraintViolationException) {
    echo 'rejected';
}
CODE;
            for ($index = 0; $index < 2; $index++) {
                $user = User::factory()->make(['role' => UserRole::Admin])->getAttributes();
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php']);
                $worker->setInput(json_encode(['connection' => $connection, 'user' => $user, 'ready' => $directory.'/ready'.$index, 'start' => $directory.'/start'], JSON_THROW_ON_ERROR));
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready*')) < 2 && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertCount(2, glob($directory.'/ready*'));
            touch($directory.'/start');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = trim($worker->getOutput());
            }
            $this->assertEqualsCanonicalizing(['created', 'rejected'], $results);
            $this->assertSame(1, User::where('role', UserRole::Admin)->count());
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
