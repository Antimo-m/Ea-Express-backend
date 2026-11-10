<?php

namespace Tests\Feature;

use App\Models\PendingAccount;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptConcurrencyTest extends TestCase
{
    private bool $resetDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Richiede un database MySQL di test isolato e pcntl.');
        }
        $this->assertMatchesRegularExpression('/\Aea_launch_test_[a-f0-9]{16}\z/', DB::getDatabaseName());
        $this->resetDatabase = true;
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->resetDatabase) {
                $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
                RefreshDatabaseState::$migrated = true;
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_simultaneous_settlements_record_one_payment_without_exceeding_the_residual(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create(['direction' => 'incoming', 'amount_cents' => 2000]);
        $this->actingAs($admin);

        $statuses = $this->race(route('pending.settle', $account), ['version' => 1, 'amount' => '20']);

        $this->assertSame([200, 409], $statuses);
        $this->assertSame(2000, $account->fresh()->settled_cents);
        $this->assertSame(1, $account->settlements()->count());
    }

    /** @param array<string, mixed> $data @return list<int> */
    private function race(string $path, array $data): array
    {
        $directory = sys_get_temp_dir().'/ea-receipt-race-'.Str::uuid();
        mkdir($directory);
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::purge();
                    while (! file_exists($directory.'/start')) {
                        usleep(1000);
                    }
                    $payload = [...$data, 'submission_key' => (string) Str::uuid()];
                    $response = $this->postJson($path, $payload);
                    file_put_contents($directory.'/'.$index, (string) $response->getStatusCode());
                } catch (\Throwable $exception) {
                    file_put_contents($directory.'/'.$index, $exception->getMessage());
                }
                exit(0);
            }
            $children[] = $pid;
        }
        touch($directory.'/start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $results = [];
        foreach ([0, 1] as $index) {
            $value = file_get_contents($directory.'/'.$index);
            $this->assertMatchesRegularExpression('/^\d{3}$/', $value);
            $results[] = (int) $value;
            unlink($directory.'/'.$index);
        }
        unlink($directory.'/start');
        rmdir($directory);
        sort($results);

        return $results;
    }
}
