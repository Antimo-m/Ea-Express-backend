<?php

namespace Tests\Feature;

use App\Support\DatabaseBackupArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ea-backup-test-'.Str::uuid();
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/offsite', 0700);
        $pdo = new PDO('sqlite:'.$this->directory.'/source.sqlite');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES users(id)); INSERT INTO users VALUES (1, 'Cliente test'); INSERT INTO orders VALUES (1, 1);");
        config(['database.default' => 'backup_test', 'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->directory.'/source.sqlite', 'prefix' => ''], 'backup.key' => base64_encode(random_bytes(32)), 'backup.path' => $this->directory.'/local', 'backup.offsite_path' => $this->directory.'/offsite']);
    }

    protected function tearDown(): void
    {
        DB::purge('backup_test');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_encrypted_backup_copies_offsite_and_restores_without_changing_source(): void
    {
        $hash = hash_file('sha256', $this->directory.'/source.sqlite');
        $this->artisan('database:backup')->assertSuccessful();
        $files = glob($this->directory.'/offsite/*.eab');
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('Cliente test', file_get_contents($files[0]));
        $this->artisan('database:backup-verify')->assertSuccessful();
        $this->artisan('database:backup --check')->assertSuccessful();
        $this->assertSame($hash, hash_file('sha256', $this->directory.'/source.sqlite'));
        $this->assertSame([], glob($this->directory.'/local/.*work*'));
    }

    public function test_corrupt_backup_and_wrong_key_are_rejected_without_restore_or_plaintext_leaks(): void
    {
        $this->artisan('database:backup')->assertSuccessful();
        $source = glob($this->directory.'/offsite/*.eab')[0];
        $key = config('backup.key');
        config(['backup.key' => base64_encode(random_bytes(32))]);
        $this->artisan('database:backup-verify')->assertFailed();
        config(['backup.key' => $key]);
        file_put_contents($source, substr(file_get_contents($source), 0, -25));
        $this->artisan('database:backup-verify')->assertFailed();
        $this->assertFileDoesNotExist($this->directory.'/local/last-verified.json');
        $this->assertSame([], glob($this->directory.'/local/.verify-*'));
    }

    public function test_missing_offsite_does_not_report_backup_active_and_local_trial_does_not_set_heartbeat(): void
    {
        config(['backup.offsite_path' => null]);
        $this->artisan('database:backup')->assertFailed();
        $this->artisan('database:backup --local-only')->assertSuccessful();
        $this->artisan('database:backup --check')->assertFailed();
        $this->assertFileDoesNotExist($this->directory.'/local/last-success.json');
    }

    public function test_retention_prunes_only_archives_after_a_successful_external_copy(): void
    {
        app(DatabaseBackupArchive::class)->directory($this->directory.'/local');
        foreach (['local', 'offsite'] as $dir) {
            file_put_contents($this->directory.'/'.$dir.'/ea-express-old.eab', 'old');
            touch($this->directory.'/'.$dir.'/ea-express-old.eab', time() - 40 * 86400);
            file_put_contents($this->directory.'/'.$dir.'/keep.txt', 'keep');
        }
        $this->artisan('database:backup')->assertSuccessful();
        $this->assertFileDoesNotExist($this->directory.'/local/ea-express-old.eab');
        $this->assertFileDoesNotExist($this->directory.'/offsite/ea-express-old.eab');
        $this->assertFileExists($this->directory.'/offsite/keep.txt');
    }
}
