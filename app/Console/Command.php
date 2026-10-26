<?php

declare(strict_types=1);

namespace App\Console;

abstract class Command
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * @param list<string> $arguments
     */
    abstract public function handle(array $arguments): int;
}
