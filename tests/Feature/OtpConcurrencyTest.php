<?php

namespace Tests\Feature;

use App\Actions\SendEmailOtp;
use App\Models\User;
use App\Notifications\RiderEmailOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OtpConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_verifications_can_consume_a_challenge_only_once(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ea-otp-race-');
        $gate = $database.'.start';
        $workers = [];
        config(['database.default' => 'otp_race', 'database.connections.otp_race' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
        ]]);
        try {
            $this->artisan('migrate', ['--database' => 'otp_race', '--force' => true])->assertSuccessful();
            Notification::fake();
            $user = User::factory()->unverified()->create();
            app(SendEmailOtp::class)->handle($user);
            $code = Notification::sent($user, RiderEmailOtp::class)->sole()->code;
            $challenge = session('email_otp_challenge');
            $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'hashing.bcrypt.rounds' => 4, 'session.driver' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
$paused = false;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$paused): void {
    if (! $paused && str_contains($query->sql, '"users"') && str_starts_with($query->sql, 'select')) {
        $paused = true;
        usleep(100000);
    }
});
touch($input['ready']);
$deadline = microtime(true) + 10;
while (! file_exists($input['gate']) && microtime(true) < $deadline) {
    usleep(1000);
}
session()->put('email_otp_challenge', $input['challenge']);
$session = app('session.store');
$session->setId($input['session_id']);
$request = Illuminate\Http\Request::create('/verify-email-otp/confirm', 'POST');
$request->setLaravelSession($session);
$app->instance('request', $request);
$user = App\Models\User::findOrFail($input['user_id']);
app(App\Support\StaffAuthentication::class)->begin($request, $user);
try {
    app(App\Actions\VerifyEmailOtp::class)->handle($user, $input['code']);
    echo 'verified';
} catch (Illuminate\Validation\ValidationException $exception) {
    echo 'rejected';
}
CODE;
            foreach ([0, 1] as $index) {
                $worker = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null']);
                $worker->setInput(json_encode(['database' => $database, 'ready' => $database.'.ready'.$index, 'gate' => $gate, 'user_id' => $user->id, 'code' => $code, 'challenge' => $challenge, 'session_id' => session()->getId()], JSON_THROW_ON_ERROR));
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
            $expected = ['verified', 'rejected'];
            sort($expected);
            $this->assertSame($expected, $results);
            $this->assertNull($user->fresh()->email_otp_hash);
            $this->assertNotNull($user->fresh()->email_verified_at);
            $this->assertDatabaseCount('economic_audits', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge('otp_race');
            foreach ([$database, $gate, $database.'.ready0', $database.'.ready1', $database.'-wal', $database.'-shm'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
