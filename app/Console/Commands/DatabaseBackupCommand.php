<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Support\DatabaseBackup;

final class DatabaseBackupCommand extends Command
{
    public function __construct(private readonly DatabaseBackup $backup)
    {
    }

    public function name(): string
    {
        return 'db:backup';
    }

    public function description(): string
    {
        return 'Write a gzipped database dump (--verify restores it into <db>_restore_test and compares rows).';
    }

    public function handle(array $arguments): int
    {
        $path = $this->option($arguments, '--path');
        $verify = in_array('--verify', $arguments, true);

        if ($verify) {
            if (!$this->backup->binaryAvailable()) {
                fwrite(STDERR, "The mysql and mysqldump clients are required for --verify." . PHP_EOL);

                return 1;
            }

            $report = $this->backup->verify($path);
            $this->printVerification($report);

            if (!$report['matches']) {
                fwrite(STDERR, 'Backup verification FAILED: row counts differ.' . PHP_EOL);

                return 1;
            }

            fwrite(STDOUT, "verified={$report['path']}\n");
        } else {
            $dump = $this->backup->dump($path);
            fwrite(STDOUT, sprintf(
                "backup=%s bytes=%d database=%s\n",
                $dump['path'],
                $dump['bytes'],
                $dump['database'],
            ));
        }

        $retained = max(1, (int) config('backup.retain_days', 14));
        $deleted = $this->backup->prune($retained);
        fwrite(STDOUT, sprintf("pruned=%d retain_days=%d\n", $deleted, $retained));

        return 0;
    }

    /**
     * @param array{path: string, bytes: int, matches: bool, tables: array<string, array{source: int, restored: int}>} $report
     */
    private function printVerification(array $report): void
    {
        foreach ($report['tables'] as $table => $counts) {
            fwrite(STDOUT, sprintf("%-36s source=%d restored=%d\n", $table, $counts['source'], $counts['restored']));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function option(array $arguments, string $name): ?string
    {
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, $name . '=')) {
                return substr($argument, strlen($name) + 1);
            }

            if ($argument === $name && isset($arguments[$index + 1])) {
                return $arguments[$index + 1];
            }
        }

        return null;
    }
}
