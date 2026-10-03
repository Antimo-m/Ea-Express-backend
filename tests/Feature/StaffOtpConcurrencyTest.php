<?php

namespace Tests\Feature;

use App\Actions\SendEmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StaffOtpConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_requests_can_consume_a_challenge_only_once(): void
    {
        $directory = sys_get_temp_dir().'/ea-otp-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/sessions', 0700);
        mkdir($directory.'/cache', 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $workers = [];
        config(['database.default' => 'otp_race', 'database.connections.otp_race' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]]);
        try {
            $this->artisan('migrate', ['--database' => 'otp_race', '--force' => true])->assertSuccessful();
            $user = User::factory()->unverified()->create();
            $code = '314159';
            $user->forceFill(['email_otp_hash' => Hash::make(SendEmailOtp::digest($user, $code)), 'email_otp_expires_at' => now()->addMinutes(15)])->save();
            $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'session.driver' => 'file', 'session.files' => $input['directory'].'/sessions', 'session.block_store' => 'file', 'cache.default' => 'file', 'cache.stores.file.path' => $input['directory'].'/cache', 'cache.stores.file.lock_path' => $input['directory'].'/cache', 'queue.default' => 'sync', 'broadcasting.default' => 'null']);
Illuminate\Support\Facades\DB::purge('sqlite');
$user = App\Models\User::findOrFail($input['user']);
$session = app('session.store');
$session->setId($input['session_id']);
$session->start();
if ($input['index'] === 0) {
    $request = Illuminate\Http\Request::create('/verify-email-otp/confirm', 'POST');
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    Illuminate\Support\Facades\Auth::guard('web')->login($user, false);
    $session->setId($input['session_id']);
    app(App\Support\StaffAuthentication::class)->begin($request, $user);
    $session->put('password_hash_web', $user->password);
    $session->put('email_otp_challenge', ['user_id' => $user->id, 'fingerprint' => hash('sha256', $user->email_otp_hash), 'challenge_id' => 'parallel-test-challenge', 'type' => 'email_verification', 'session_binding' => app(App\Support\StaffAuthentication::class)->binding($request), 'issued_at' => time(), 'expires_at' => time() + 900]);
    $session->save();
}
touch($input['directory'].'/ready'.$input['index']);
$deadline = microtime(true) + 10;
while (! file_exists($input['directory'].'/start') && microtime(true) < $deadline) { usleep(1000); }
$name = config('session.cookie');
$cookie = app('encrypter')->encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$input['session_id'], false);
$request = Illuminate\Http\Request::create('/verify-email-otp/confirm', 'POST', ['code' => $input['code']], [$name => $cookie], [], ['HTTP_ACCEPT' => 'application/json']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
PHP;
            foreach ([0, 1] as $index) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php']);
                $worker->setInput(json_encode(['database' => $database, 'directory' => $directory, 'user' => $user->id, 'code' => $code, 'session_id' => str_repeat('a', 40), 'index' => $index], JSON_THROW_ON_ERROR));
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while ((! file_exists($directory.'/ready0') || ! file_exists($directory.'/ready1')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready0');
            $this->assertFileExists($directory.'/ready1');
            touch($directory.'/start');
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
            }
            $this->assertCount(1, array_filter($statuses, fn (int $status): bool => $status === 302));
            $this->assertCount(1, array_filter($statuses, fn (int $status): bool => in_array($status, [401, 403, 422], true)));
            $this->assertNotNull($user->fresh()->email_verified_at);
            $this->assertNull($user->fresh()->email_otp_hash);
            $this->assertDatabaseCount('economic_audits', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('otp_race');
            File::deleteDirectory($directory);
        }
    }
}
