<?php

namespace App\Support;

use Throwable;

/**
 * Indice dei Comuni italiani per nome normalizzato, compilato una sola volta in un file PHP.
 *
 * Con OPcache il file compilato resta in memoria condivisa: ogni richiesta riceve l'indice senza leggere né
 * decodificare l'archivio JSON da 1,4 MB. Se il file manca o non corrisponde più all'archivio, l'indice viene
 * ricostruito in memoria (stesso risultato di prima) e il file viene rigenerato in modo atomico.
 * Senza OPcache (CLI, test) includere il file costerebbe più della decodifica JSON: si legge l'archivio direttamente.
 */
class PostalCodeIndex
{
    /** Incrementare quando cambia la struttura dell'indice o la normalizzazione dei nomi. */
    public const int Format = 1;

    /**
     * @param  bool|null  $useCompiled  null = usa il file compilato solo se OPcache è attivo per questo processo
     */
    public function __construct(private ?string $cachePath = null, private ?string $sourcePath = null, private ?bool $useCompiled = null) {}

    public function cachePath(): string
    {
        return $this->cachePath ?? app()->bootstrapPath('cache/postal-codes.php');
    }

    public function sourcePath(): string
    {
        return $this->sourcePath ?? resource_path('italian-postal-codes.json');
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function load(): array
    {
        if (! ($this->useCompiled ?? self::opcacheActive())) {
            return $this->build();
        }
        $signature = $this->signature();
        $cached = $this->cached();
        if ($cached !== null && $cached['signature'] === $signature) {
            return $cached['municipalities'];
        }
        $municipalities = $this->build();
        $this->write($municipalities, $signature);

        return $municipalities;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function build(): array
    {
        $municipalities = [];
        $data = json_decode((string) file_get_contents($this->sourcePath()), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['municipalities'] as $place) {
            foreach ([$place['name'], ...($place['aliases'] ?? [])] as $name) {
                $municipalities[ShippingQuote::cityKey($name)][] = $place;
            }
        }

        return $municipalities;
    }

    /**
     * Compila l'indice nel file di cache; restituisce il numero di nomi indicizzati.
     */
    public function compile(): int
    {
        $municipalities = $this->build();
        if (! $this->write($municipalities, $this->signature())) {
            throw new \RuntimeException('Impossibile scrivere la cache dei CAP in '.$this->cachePath().'.');
        }

        return count($municipalities);
    }

    public function isFresh(): bool
    {
        return ($this->cached()['signature'] ?? null) === $this->signature();
    }

    public function clear(): void
    {
        if (is_file($this->cachePath())) {
            unlink($this->cachePath());
        }
    }

    private static function opcacheActive(): bool
    {
        return function_exists('opcache_get_status')
            && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Firma economica dell'archivio sorgente: dimensione e data di modifica, senza leggere il contenuto.
     */
    private function signature(): string
    {
        clearstatcache(true, $this->sourcePath());

        return self::Format.':'.filesize($this->sourcePath()).':'.filemtime($this->sourcePath());
    }

    /** @return array{signature: string, municipalities: array<string, list<array<string, mixed>>>}|null */
    private function cached(): ?array
    {
        if (! is_file($this->cachePath())) {
            return null;
        }
        try {
            $cached = include $this->cachePath();
        } catch (Throwable) {
            return null;
        }

        return is_array($cached) && is_string($cached['signature'] ?? null) && is_array($cached['municipalities'] ?? null) ? $cached : null;
    }

    /** @param array<string, list<array<string, mixed>>> $municipalities */
    private function write(array $municipalities, string $signature): bool
    {
        $directory = dirname($this->cachePath());
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }
        $temporary = @tempnam($directory, 'postal-codes-');
        if ($temporary === false) {
            return false;
        }
        $contents = '<?php return '.var_export(['signature' => $signature, 'municipalities' => $municipalities], true).';'.PHP_EOL;
        if (@file_put_contents($temporary, $contents) === false || ! @chmod($temporary, 0644) || ! @rename($temporary, $this->cachePath())) {
            @unlink($temporary);

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->cachePath(), true);
        }

        return true;
    }
}
