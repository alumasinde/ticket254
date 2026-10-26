<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Support\RateLimiter;

final class RateLimitPruneCommand extends Command
{
    public function __construct(private readonly RateLimiter $rateLimits)
    {
    }

    public function name(): string
    {
        return 'rate-limit:prune';
    }

    public function description(): string
    {
        return 'Delete expired rate-limit windows (--days=1 grace period).';
    }

    public function handle(array $arguments): int
    {
        $days = $this->days($arguments);
        $deleted = $this->rateLimits->prune($days);

        fwrite(STDOUT, sprintf("deleted=%d grace_days=%d\n", $deleted, $days));

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function days(array $arguments): int
    {
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--days=')) {
                return max(0, (int) substr($argument, 7));
            }

            if ($argument === '--days' && isset($arguments[$index + 1])) {
                return max(0, (int) $arguments[$index + 1]);
            }
        }

        return 1;
    }
}
