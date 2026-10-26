<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Database\Database;
use App\Database\Migrator;

final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly Database $database)
    {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show which migrations have been applied.';
    }

    public function handle(array $arguments): int
    {
        $migrator = new Migrator($this->database, BASE_PATH . '/db');
        $modified = false;

        fwrite(STDOUT, sprintf("%-42s %-10s %-19s\n", 'Migration', 'Status', 'Applied at'));
        fwrite(STDOUT, str_repeat('-', 75) . PHP_EOL);

        foreach ($migrator->status() as $row) {
            $state = $row['applied'] ? ($row['modified'] ? 'modified' : 'applied') : 'pending';
            $modified = $modified || $row['modified'];

            fwrite(STDOUT, sprintf(
                "%-42s %-10s %-19s\n",
                $row['filename'],
                $state,
                $row['applied_at'] ?? '-',
            ));
        }

        return $modified ? 1 : 0;
    }
}
