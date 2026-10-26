<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Container;
use Psr\Log\LoggerInterface;
use Throwable;

final class Kernel
{
    /** @var array<string, Command>|null */
    private ?array $commands = null;

    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? null;

        if ($name === null || in_array($name, ['list', 'help', '--help', '-h'], true)) {
            $this->printUsage();

            return 0;
        }

        $command = $this->commands()[$name] ?? null;

        if ($command === null) {
            fwrite(STDERR, "Unknown command [$name]." . PHP_EOL);
            $this->printUsage(STDERR);

            return 1;
        }

        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            fwrite(STDOUT, $command->description() . PHP_EOL);

            return 0;
        }

        try {
            return $command->handle(array_slice($argv, 2));
        } catch (Throwable $exception) {
            $this->logger->error('CLI command failed', [
                'command' => $name,
                'exception' => $exception,
            ]);
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * @return array<string, Command>
     */
    private function commands(): array
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        $this->commands = [];
        $files = glob(BASE_PATH . '/app/Console/Commands/*.php') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $class = 'App\\Console\\Commands\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $command = $this->container->get($class);

            if ($command instanceof Command) {
                $this->commands[$command->name()] = $command;
            }
        }

        ksort($this->commands);

        return $this->commands;
    }

    /**
     * @param resource $stream
     */
    private function printUsage($stream = STDOUT): void
    {
        fwrite($stream, 'HudumaDesk CLI' . PHP_EOL . PHP_EOL);
        fwrite($stream, 'Usage:' . PHP_EOL);
        fwrite($stream, '  php hd <command> [options]' . PHP_EOL . PHP_EOL);
        fwrite($stream, 'Commands:' . PHP_EOL);

        foreach ($this->commands() as $command) {
            fwrite($stream, sprintf("  %-18s %s\n", $command->name(), $command->description()));
        }
    }
}
