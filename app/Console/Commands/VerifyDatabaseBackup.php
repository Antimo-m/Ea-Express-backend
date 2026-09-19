<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackupArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VerifyDatabaseBackup extends Command
{
    protected $signature = 'database:backup-verify {archive? : Archivio cifrato; senza argomento verifica l’ultima copia esterna}';

    protected $description = 'Decifra e ripristina in SQLite temporaneo o MySQL Docker isolato, senza modificare il database applicativo';

    public function handle(DatabaseBackupArchive $archive): int
    {
        $directory = rtrim((string) config('backup.path'), '/');
        $work = $directory.'/.verify-'.Str::uuid();
        try {
            $source = $this->argument('archive');
            if (! $source) {
                $offsite = rtrim((string) config('backup.offsite_path'), '/');
                if (! $offsite) {
                    throw new \RuntimeException('BACKUP_OFFSITE_PATH non configurato.');
                }
                $files = glob($offsite.'/ea-express-*.eab');
                rsort($files);
                $source = $files[0] ?? null;
            }
            if (! $source || ! is_file($source)) {
                throw new \RuntimeException('Nessun archivio da verificare.');
            }
            $archive->directory($work);
            $metadata = $archive->decrypt($source, $work.'/restored');
            $archive->verifyRestore($work.'/restored', $metadata['driver']);
            if (! $this->argument('archive')) {
                file_put_contents($directory.'/last-verified.json', json_encode(['timestamp' => time(), 'archive' => basename($source)]), LOCK_EX);
            }
            Log::info('Ripristino backup verificato in isolamento.', ['archive' => basename($source), 'driver' => $metadata['driver']]);
            $this->info('Ripristino isolato e integrità verificati. Nessun dato applicativo sovrascritto.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Verifica ripristino backup fallita.', ['error_type' => class_basename($exception)]);
            $this->error($exception instanceof \RuntimeException ? $exception->getMessage() : 'Verifica fallita. Controllare archivio, chiave e strumenti di ripristino.');

            return self::FAILURE;
        } finally {
            if (is_file($work.'/restored')) {
                unlink($work.'/restored');
            }
            if (is_dir($work)) {
                rmdir($work);
            }
        }
    }
}
