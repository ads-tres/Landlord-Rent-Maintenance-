<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Daily gzipped database backup to storage/app/private/backups, keeping 14 days.
 * Copy this folder to a second server inside Ethiopia (data must stay in the country).
 */
#[Signature('backup:database {--keep=14 : Days of backups to keep}')]
#[Description('Back up the database (pg_dump or SQLite copy) and remove old backups')]
class BackupDatabase extends Command
{
    public const DIR = 'backups';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);

        $name = sprintf('%s/%s-%s.sql.gz', self::DIR, $connection, now()->format('Y-m-d_His'));
        $target = $disk->path($name);

        $ok = match ($config['driver']) {
            'pgsql' => $this->pgDump($config, $target),
            'sqlite' => $this->sqliteCopy($config['database'], $target),
            default => $this->failWith("Unsupported driver {$config['driver']}"),
        };

        if (! $ok) {
            return self::FAILURE;
        }

        $removed = $this->prune((int) $this->option('keep'));
        $this->info(sprintf('Backup written: %s (%s KB). Removed %d old backup(s).', $name, number_format(filesize($target) / 1024, 1), $removed));

        return self::SUCCESS;
    }

    private function pgDump(array $config, string $target): bool
    {
        $process = Process::fromShellCommandline(
            'pg_dump --no-owner --no-privileges -h "$H" -p "$P" -U "$U" "$D" | gzip > "$T"',
            null,
            [
                'H' => $config['host'], 'P' => (string) $config['port'], 'U' => $config['username'],
                'D' => $config['database'], 'T' => $target, 'PGPASSWORD' => (string) $config['password'],
            ],
            null,
            900,
        );
        $process->run();

        if (! $process->isSuccessful() || filesize($target) < 100) {
            @unlink($target);

            return $this->failWith('pg_dump failed: '.$process->getErrorOutput());
        }

        return true;
    }

    private function sqliteCopy(string $path, string $target): bool
    {
        if ($path === ':memory:' || ! is_file($path)) {
            return $this->failWith('SQLite database file not found.');
        }

        file_put_contents($target, gzencode((string) file_get_contents($path), 9));

        return true;
    }

    private function prune(int $keepDays): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $removed = 0;

        foreach ($disk->files(self::DIR) as $file) {
            if (str_ends_with($file, '.sql.gz') && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function failWith(string $message): bool
    {
        $this->error($message);

        return false;
    }
}
