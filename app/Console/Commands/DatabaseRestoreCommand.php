<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Support\DatabaseBackup;

final class DatabaseRestoreCommand extends Command
{
    public function __construct(private readonly DatabaseBackup $backup)
    {
    }

    public function name(): string
    {
        return 'db:restore';
    }

    public function description(): string
    {
        return 'Restore a dump: db:restore <file> [--database=name] [--create] [--force].';
    }

    public function handle(array $arguments): int
    {
        $path = $this->fileArgument($arguments);

        if (!is_string($path) || str_starts_with($path, '--')) {
            fwrite(STDERR, 'Usage: php hd db:restore <file.sql.gz> [--database=name] [--create] [--force]' . PHP_EOL);

            return 1;
        }

        $configured = (string) config('database.connections.mysql.database');
        $target = $this->option($arguments, '--database') ?? $configured;
        $force = in_array('--force', $arguments, true);
        $create = in_array('--create', $arguments, true);

        if (!$this->safeTarget($target) && !$force) {
            fwrite(STDERR, sprintf(
                "Refusing to overwrite [%s]. Pass --force after taking a fresh backup.%s",
                $target,
                PHP_EOL,
            ));

            return 1;
        }

        $result = $this->backup->restore($path, $target, $create);

        fwrite(STDOUT, sprintf(
            "restored=%s database=%s tables=%d rows=%d\n",
            $path,
            $target,
            $result['tables'],
            $result['rows'],
        ));

        return 0;
    }

    private function safeTarget(string $target): bool
    {
        return str_ends_with($target, '_test');
    }

    /**
     * @param list<string> $arguments
     */
    private function fileArgument(array $arguments): ?string
    {
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];

            if ($argument === '--database') {
                $index++;
                continue;
            }

            if (!str_starts_with($argument, '--')) {
                return $argument;
            }
        }

        return null;
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
