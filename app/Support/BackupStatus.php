<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class BackupStatus
{
    /** @return array{enabled: bool, local: bool, backup_at: ?string, verified_at: ?string, backup_fresh: bool, restore_fresh: bool, scheduler_alive: bool} */
    public function summary(): array
    {
        $local = config('backup.mode') === 'local';
        $prefix = $local ? 'last-local-' : 'last-';
        $backup = $this->timestamp($prefix.'success.json');
        $verified = $this->timestamp($prefix.'verified.json');
        $heartbeat = cache()->get('scheduler:last_seen_at');

        return [
            'enabled' => (bool) config('backup.enabled'), 'local' => $local,
            'backup_at' => $backup ? Carbon::createFromTimestamp($backup)->timezone('Europe/Rome')->format('d/m/Y H:i') : null,
            'verified_at' => $verified ? Carbon::createFromTimestamp($verified)->timezone('Europe/Rome')->format('d/m/Y H:i') : null,
            'backup_fresh' => $backup && $backup >= now()->subHours(26)->timestamp,
            'restore_fresh' => $verified && $verified >= now()->subDays(8)->timestamp,
            'scheduler_alive' => is_numeric($heartbeat) && $heartbeat <= now()->timestamp && $heartbeat >= now()->subMinutes(5)->timestamp,
        ];
    }

    private function timestamp(string $name): ?int
    {
        $file = rtrim((string) config('backup.path'), '/').'/'.$name;
        if (! is_readable($file)) {
            return null;
        }
        $status = json_decode(file_get_contents($file), true);
        $timestamp = $status['timestamp'] ?? null;
        $archive = $status['archive'] ?? null;
        $directory = str_starts_with($name, 'last-local-') ? config('backup.path') : config('backup.offsite_path');
        if (! is_string($archive) || basename($archive) !== $archive || ! $directory || ! is_readable(rtrim($directory, '/').'/'.$archive)) {
            return null;
        }

        return is_int($timestamp) && $timestamp <= now()->timestamp ? $timestamp : null;
    }
}
