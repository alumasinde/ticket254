<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Services\Notifications\OutboxWorker;

final class QueueWorkCommand extends Command
{
    public function __construct(private readonly OutboxWorker $worker)
    {
    }

    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Send queued notifications, retrying failures (--once, --limit=25).';
    }

    public function handle(array $arguments): int
    {
        $once = in_array('--once', $arguments, true);
        $limit = $this->limit($arguments);

        do {
            $report = $this->worker->work($limit);

            fwrite(STDOUT, sprintf(
                "claimed=%d sent=%d failed=%d\n",
                $report['claimed'],
                $report['sent'],
                $report['failed'],
            ));

            if ($once) {
                break;
            }

            if ($report['claimed'] === 0) {
                sleep(2);
            }
        } while (true);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function limit(array $arguments): int
    {
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--limit=')) {
                return max(1, (int) substr($argument, 8));
            }

            if ($argument === '--limit' && isset($arguments[$index + 1])) {
                return max(1, (int) $arguments[$index + 1]);
            }
        }

        return 25;
    }
}
