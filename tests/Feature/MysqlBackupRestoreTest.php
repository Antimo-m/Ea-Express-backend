<?php

namespace Tests\Feature;

use App\Support\DatabaseBackupArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class MysqlBackupRestoreTest extends TestCase
{
    public function test_mysql_dump_can_be_encrypted_and_decrypted_without_changing_the_source(): void
    {
        $this->withIsolatedMysql(function ($db, string $directory): void {
            $this->artisan('database:backup')->assertSuccessful();
            $archives = glob($directory.'/offsite/*.eab');
            $this->assertCount(1, $archives);
            $this->assertStringNotContainsString('Cliente sintetico', file_get_contents($archives[0]));
            $metadata = app(DatabaseBackupArchive::class)->decrypt($archives[0], $directory.'/restored.sql');
            $this->assertSame('mysql', $metadata['driver']);
            $this->assertStringContainsString('Cliente sintetico', file_get_contents($directory.'/restored.sql'));
            $this->assertSame('Cliente sintetico', $db->table('users')->value('name'));
            $this->assertSame(1, $db->table('orders')->count());
        });
    }

    public function test_encrypted_mysql_backup_restores_in_an_isolated_container_without_changing_the_source(): void
    {
        if (getenv('EA_TEST_MYSQL_BACKUP_RESTORE') !== '1') {
            $this->markTestSkipped('Richiede anche il client Docker e la possibilità di avviare un container di ripristino isolato.');
        }
        $this->withIsolatedMysql(function ($db, string $directory): void {
            $this->artisan('database:backup')->assertSuccessful();
            $archives = glob($directory.'/offsite/*.eab');
            $this->assertCount(1, $archives);
            $this->assertStringNotContainsString('Cliente sintetico', file_get_contents($archives[0]));
            $this->artisan('database:backup-verify')->assertSuccessful();
            $this->artisan('database:backup --check')->assertSuccessful();
            $this->assertSame('Cliente sintetico', $db->table('users')->value('name'));
            $this->assertSame(1, $db->table('orders')->count());
            $this->assertSame([], glob($directory.'/local/.verify-*'));
        });
    }

    private function withIsolatedMysql(callable $assertBackup): void
    {
        if (getenv('EA_TEST_MYSQL_BACKUP') !== '1') {
            $this->markTestSkipped('Richiede MySQL locale isolato e mysqldump.');
        }
        $source = config('database.connections.mysql');
        $this->assertContains($source['host'], ['127.0.0.1', 'localhost', '::1']);
        $this->assertMatchesRegularExpression('/\Aea_launch_test_[a-f0-9]{16}\z/', $source['database']);
        $owner = new PDO('mysql:host='.$source['host'].';port='.$source['port'].';charset=utf8mb4', $source['username'], $source['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'ea_backup_test_'.bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir().'/ea-mysql-backup-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/offsite', 0700);
        $owner->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            unset($source['name']);
            config([
                'database.default' => 'backup_mysql_test',
                'database.connections.backup_mysql_test' => [...$source, 'url' => null, 'database' => $database],
                'backup.key' => base64_encode(random_bytes(32)),
                'backup.key_file' => null,
                'backup.path' => $directory.'/local',
                'backup.offsite_path' => $directory.'/offsite',
            ]);
            $db = DB::connection('backup_mysql_test');
            $db->unprepared('CREATE TABLE users (id BIGINT PRIMARY KEY, name VARCHAR(100) NOT NULL)');
            $db->unprepared('CREATE TABLE orders (id BIGINT PRIMARY KEY, customer_id BIGINT NOT NULL, FOREIGN KEY (customer_id) REFERENCES users(id))');
            $db->unprepared('CREATE TABLE migrations (id BIGINT PRIMARY KEY, migration VARCHAR(100) NOT NULL)');
            $db->table('users')->insert(['id' => 1, 'name' => 'Cliente sintetico']);
            $db->table('orders')->insert(['id' => 1, 'customer_id' => 1]);
            $db->table('migrations')->insert(['id' => 1, 'migration' => 'test_sintetico']);

            $assertBackup($db, $directory);
        } finally {
            DB::purge('backup_mysql_test');
            $owner->exec('DROP DATABASE `'.$database.'`');
            File::deleteDirectory($directory);
        }
    }
}
