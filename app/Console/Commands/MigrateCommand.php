<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Database\Database;
use App\Database\Migrator;

final class MigrateCommand extends Command
{
    public function __construct(private readonly Database $database)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run pending database migrations.';
    }

    public function handle(array $arguments): int
    {
        $migrator = new Migrator($this->database, BASE_PATH . '/db');
        $count = $migrator->migrate(static function (string $filename, int $index, int $total): void {
            fwrite(STDOUT, sprintf("  applied %s (%d/%d)\n", $filename, $index, $total));
        });

        fwrite(STDOUT, $count === 0
            ? 'Nothing to migrate.' . PHP_EOL
            : sprintf('Applied %d migration(s).' . PHP_EOL, $count));

        return 0;
    }
}
