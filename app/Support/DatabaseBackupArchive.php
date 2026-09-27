<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupArchive
{
    private function key(): string
    {
        $encoded = (string) config('backup.key');
        if ($encoded === '' && config('backup.key_file') && is_readable(config('backup.key_file'))) {
            $encoded = trim(file_get_contents(config('backup.key_file')));
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_KEY deve contenere una chiave casuale di 32 byte in Base64.');
        }

        return $key;
    }

    public function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new RuntimeException('Impossibile creare la directory privata dei backup.');
        }
        if (! is_writable($path)) {
            throw new RuntimeException('Directory backup non scrivibile.');
        }
    }

    public function snapshot(string $destination): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($destination));
            chmod($destination, 0600);

            return 'sqlite';
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('Backup disponibile per MySQL e SQLite.');
        }
        $config = $connection->getConfig();
        $options = tempnam(dirname($destination), '.mysql-');
        chmod($options, 0600);
        try {
            $quote = fn (string $value): string => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
            $content = "[client]\n";
            foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'password' => 'password', 'socket' => 'unix_socket'] as $option => $key) {
                if (! empty($config[$key])) {
                    $content .= $option.'='.$quote((string) $config[$key])."\n";
                }
            }
            file_put_contents($options, $content);
            $process = new Process([(string) config('backup.mysqldump'), '--defaults-extra-file='.$options, '--single-transaction', '--quick', '--no-tablespaces', '--set-gtid-purged=OFF', '--routines', '--events', '--triggers', '--hex-blob', '--result-file='.$destination, '--', $config['database']]);
            $process->setTimeout(1800);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($destination) || filesize($destination) === 0) {
                throw new RuntimeException('Dump MySQL fallito; verificare binario, privilegi e spazio disco.');
            }
            chmod($destination, 0600);

            return 'mysql';
        } finally {
            unlink($options);
        }
    }

    public function encrypt(string $source, string $destination, string $driver): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if (! $input || ! $output) {
            throw new RuntimeException('Impossibile aprire archivio backup.');
        }
        chmod($destination, 0600);
        try {
            fwrite($output, 'EAEXP1'.$header);
            $metadata = json_encode(['driver' => $driver, 'sha256' => hash_file('sha256', $source)], JSON_THROW_ON_ERROR);
            $this->writeChunk($output, sodium_crypto_secretstream_xchacha20poly1305_push($state, $metadata));
            while (! feof($input)) {
                $this->writeChunk($output, sodium_crypto_secretstream_xchacha20poly1305_push($state, fread($input, 1048576)));
            }
            $this->writeChunk($output, sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** @param resource $stream */
    private function writeChunk(mixed $stream, string $chunk): void
    {
        $bytes = pack('N', strlen($chunk)).$chunk;
        if (fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Scrittura backup incompleta.');
        }
    }

    /** @return array{driver: string, sha256: string} */
    public function decrypt(string $source, string $destination): array
    {
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if (! $input || ! $output) {
            throw new RuntimeException('Archivio non leggibile o destinazione già esistente.');
        }
        chmod($destination, 0600);
        try {
            if (fread($input, 6) !== 'EAEXP1') {
                throw new RuntimeException('Formato archivio non valido.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $this->key());
            $metadata = null;
            $final = false;
            while (! feof($input)) {
                $lengthBytes = fread($input, 4);
                if (strlen($lengthBytes) !== 4) {
                    break;
                }
                $length = unpack('N', $lengthBytes)[1];
                if ($length < 17 || $length > 1048593) {
                    throw new RuntimeException('Lunghezza archivio non valida.');
                }
                $chunk = fread($input, $length);
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($decoded === false) {
                    throw new RuntimeException('Backup alterato o chiave errata.');
                }
                [$plain, $tag] = $decoded;
                if ($metadata === null) {
                    $metadata = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
                } elseif (fwrite($output, $plain) !== strlen($plain)) {
                    throw new RuntimeException('Spazio insufficiente per il ripristino.');
                }
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $final = true;
                    if (fread($input, 1) !== '') {
                        throw new RuntimeException('Dati inattesi in coda al backup.');
                    }
                    break;
                }
            }
            fflush($output);
            if (! $final || ! isset($metadata['sha256'], $metadata['driver']) || ! hash_equals($metadata['sha256'], hash_file('sha256', $destination))) {
                throw new RuntimeException('Backup incompleto o checksum non valido.');
            }

            return $metadata;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function verifyRestore(string $snapshot, string $driver): void
    {
        if ($driver === 'sqlite') {
            $db = new PDO('sqlite:'.$snapshot);
            if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $db->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('Integrità del database ripristinato non valida.');
            }
            $db->query('SELECT count(*) FROM orders')->fetchColumn();
            $db->query('SELECT count(*) FROM users')->fetchColumn();

            return;
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('Motore backup non supportato.');
        }
        $docker = (string) config('backup.docker');
        $image = (string) config('backup.mysql_restore_image');
        $this->run([$docker, 'image', 'inspect', $image]);
        $container = 'ea-restore-'.strtolower((string) Str::ulid());
        try {
            $this->run([$docker, 'run', '--detach', '--rm', '--network', 'none', '--name', $container, '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=ea_restore', $image]);
            $ready = false;
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $ping = new Process([$docker, 'exec', $container, 'mysql', '--protocol=tcp', '-h127.0.0.1', '-uroot', 'ea_restore', '-e', 'SELECT 1']);
                $ping->setTimeout(5)->run();
                if ($ping->isSuccessful()) {
                    $ready = true;
                    break;
                }
                usleep(1000000);
            }
            if (! $ready) {
                throw new RuntimeException('MySQL isolato non pronto.');
            }
            $stream = fopen($snapshot, 'rb');
            try {
                $restore = new Process([$docker, 'exec', '-i', $container, 'mysql', '-uroot', 'ea_restore']);
                $restore->setInput($stream)->setTimeout(1800)->run();
                if (! $restore->isSuccessful()) {
                    preg_match('/ERROR [0-9]+ \([A-Z0-9]+\)/', $restore->getErrorOutput(), $error);
                    throw new RuntimeException('Importazione nel container isolato fallita'.(isset($error[0]) ? ' · '.$error[0] : '').'.');
                }
            } finally {
                fclose($stream);
            }
            $client = [$docker, 'exec', $container, 'mysql', '-uroot', '--batch', '--skip-column-names', 'ea_restore', '-e'];
            $tables = array_filter(explode("\n", trim($this->run([...$client, 'SHOW TABLES']))));
            if (! $tables) {
                throw new RuntimeException('Il database ripristinato non contiene tabelle.');
            }
            foreach (array_chunk($tables, 50) as $batch) {
                $names = array_map(fn (string $table): string => '`'.str_replace('`', '``', $table).'`', $batch);
                $checks = $this->run([...$client, 'CHECK TABLE '.implode(', ', $names)]);
                $verified = [];
                foreach (explode("\n", trim($checks)) as $row) {
                    $columns = explode("\t", $row);
                    if (count($columns) !== 4 || $columns[2] === 'error' || $columns[2] === 'warning') {
                        throw new RuntimeException('Integrità delle tabelle ripristinate non valida.');
                    }
                    if ($columns[2] === 'status' && in_array($columns[3], ['OK', 'Table is already up to date'], true)) {
                        $verified[$columns[0]] = true;
                    }
                }
                if (count($verified) !== count($batch)) {
                    throw new RuntimeException('Controllo delle tabelle ripristinate incompleto.');
                }
            }
            $this->run([$docker, 'exec', $container, 'mysql', '-uroot', 'ea_restore', '-e', 'SELECT COUNT(*) FROM orders; SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM migrations;']);
        } finally {
            (new Process([$docker, 'rm', '-f', $container]))->setTimeout(30)->run();
        }
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout(120)->run();
        if (! $process->isSuccessful()) {
            preg_match('/(?:ERROR [0-9]+ \([A-Z0-9]+\)|Got error: [0-9]+)/', $process->getErrorOutput(), $error);
            $step = in_array('mysqlcheck', $arguments, true) ? 'controllo tabelle' : (in_array('mysql', $arguments, true) ? 'lettura tabelle' : (in_array('inspect', $arguments, true) ? 'immagine MySQL' : 'avvio container'));
            throw new RuntimeException('Verifica isolata fallita ('.$step.')'.(isset($error[0]) ? ' · '.$error[0] : '').'.');
        }

        return $process->getOutput();
    }
}
