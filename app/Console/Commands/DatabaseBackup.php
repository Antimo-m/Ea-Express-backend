<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackupArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DatabaseBackup extends Command
{
    protected $signature = 'database:backup {--local-only : Crea una copia locale di prova senza dichiararla offsite} {--check : Verifica la freschezza dei backup e dei test di ripristino}';

    protected $description = 'Backup completo cifrato MySQL/SQLite con copia esterna, retention e log';

    public function handle(DatabaseBackupArchive $archive): int
    {
        $directory = rtrim((string) config('backup.path'), '/');
        if ($this->option('check')) {
            foreach (['last-success.json' => 26 * 3600, 'last-verified.json' => 8 * 86400] as $name => $maxAge) {
                $status = is_file($directory.'/'.$name) ? json_decode(file_get_contents($directory.'/'.$name), true) : null;
                if (! $status || time() - ($status['timestamp'] ?? 0) > $maxAge) {
                    $this->error('Backup o verifica di ripristino assente/scaduta: '.$name);

                    return self::FAILURE;
                }
            }
            $this->info('Backup esterno e ripristino verificati entro le soglie.');

            return self::SUCCESS;
        }
        $work = $directory.'/.work-'.Str::uuid();
        try {
            $offsite = rtrim((string) config('backup.offsite_path'), '/');
            if (! $this->option('local-only') && (! $offsite || ! is_dir($offsite) || ! is_writable($offsite))) {
                throw new \RuntimeException('Configurare BACKUP_OFFSITE_PATH su volume esterno montato e scrivibile.');
            }
            $archive->directory($directory);
            if ($offsite && (realpath($directory) === realpath($offsite) || str_starts_with(realpath($offsite).'/', realpath($directory).'/'))) {
                throw new \RuntimeException('La copia esterna deve essere separata dalla directory locale.');
            }
            $archive->directory($work);
            $driver = $archive->snapshot($work.'/snapshot');
            $name = 'ea-express-'.gmdate('Ymd-His').'-'.Str::uuid().'.eab';
            $archive->encrypt($work.'/snapshot', $work.'/archive', $driver);
            $archive->decrypt($work.'/archive', $work.'/checked');
            if (! rename($work.'/archive', $directory.'/'.$name)) {
                throw new \RuntimeException('Impossibile finalizzare il backup.');
            }
            if (! $this->option('local-only')) {
                $temporary = $offsite.'/.'.$name.'.partial';
                if (! copy($directory.'/'.$name, $temporary)) {
                    throw new \RuntimeException('Copia esterna fallita.');
                }
                chmod($temporary, 0600);
                if (! hash_equals(hash_file('sha256', $directory.'/'.$name), hash_file('sha256', $temporary)) || ! rename($temporary, $offsite.'/'.$name)) {
                    throw new \RuntimeException('Verifica della copia esterna fallita.');
                }
                file_put_contents($directory.'/last-success.json', json_encode(['timestamp' => time(), 'archive' => $name]), LOCK_EX);
                foreach ([$directory, $offsite] as $path) {
                    foreach (glob($path.'/ea-express-*.eab') as $old) {
                        if (basename($old) !== $name && filemtime($old) < time() - max(1, (int) config('backup.retention_days')) * 86400) {
                            unlink($old);
                        }
                    }
                }
            }
            Log::info('Backup database creato e autenticato.', ['archive' => $name, 'offsite' => ! $this->option('local-only'), 'restore_verified' => false]);
            $this->info($directory.'/'.$name);
            $this->warn('Integrità archivio verificata; eseguire database:backup-verify per il test di ripristino.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Backup database fallito.', ['error_type' => class_basename($exception)]);
            $this->error($exception instanceof \RuntimeException ? $exception->getMessage() : 'Backup fallito. Verificare configurazione e log.');

            return self::FAILURE;
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($work)) {
                rmdir($work);
            }
        }
    }
}
