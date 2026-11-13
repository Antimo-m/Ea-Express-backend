<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecoveryConcurrencyTest extends TestCase
{
    public function test_simultaneous_requests_with_the_same_token_change_the_password_only_once(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ea-recovery-race-');
        $gate = $database.'.start';
        $workers = [];
        config(['database.default' => 'recovery_race', 'database.connections.recovery_race' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
        ]]);
        try {
            $this->artisan('migrate', ['--database' => 'recovery_race', '--force' => true, '--no-interaction' => true])->assertSuccessful();
            $user = User::factory()->create();
            $token = Password::broker('users')->createToken($user);
            $passwordHash = $user->fresh()->password;
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'hashing.bcrypt.rounds' => 4, 'session.driver' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
touch($input['ready']);
$deadline = microtime(true) + 10;
while (! file_exists($input['gate']) && microtime(true) < $deadline) {
    usleep(1000);
}
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create($input['path'], "POST", $input['credentials'], [], [], ['HTTP_ACCEPT' => 'application/json']);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
CODE;
            foreach (['First-password-123', 'Second-password-123'] as $index => $password) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null', 'AUTH_PASSWORD_BREACH_CHECK' => 'false', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'AUTH_OTP_MAILER' => 'array', 'CACHE_STORE' => 'array', 'AUTH_SECURITY_STORE' => 'array']);
                $worker->setInput(json_encode(['database' => $database, 'ready' => $database.'.ready'.$index, 'gate' => $gate, 'path' => '/reset-password', 'credentials' => ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password]], JSON_THROW_ON_ERROR));
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
                $results[] = trim($worker->getOutput());
            }
            sort($results);
            $this->assertSame(['302', '422'], $results);
            $this->assertFalse(Password::broker('users')->tokenExists($user, $token));
            $this->assertNotSame($passwordHash, $user->fresh()->password);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('recovery_race');
            foreach ([$database, $gate, $database.'.ready0', $database.'.ready1', $database.'-wal', $database.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
