<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Services\Demo\GleeHotelDemoSeeder;

final class DemoSeedGleeCommand extends Command
{
    public function __construct(private readonly GleeHotelDemoSeeder $seeder)
    {
    }

    public function name(): string
    {
        return 'demo:seed-glee';
    }

    public function description(): string
    {
        return 'Seed the Glee Hotel pilot workspace (--email= --password= --slug=).';
    }

    public function handle(array $arguments): int
    {
        $result = $this->seeder->seed([
            'email' => $this->option($arguments, '--email'),
            'password' => $this->option($arguments, '--password'),
            'slug' => $this->option($arguments, '--slug'),
        ]);

        fwrite(STDOUT, sprintf(
            "workspace=Glee Hotel slug=%s owner=%s password=%s%s\n",
            $result['slug'],
            $result['owner_email'],
            $result['password'],
            $result['generated_password'] ? ' (generated)' : '',
        ));
        fwrite(STDOUT, sprintf(
            "members=%d sites=%d tickets=%d\n",
            $result['members'],
            $result['sites'],
            $result['tickets'],
        ));
        fwrite(STDOUT, 'staff=' . implode(',', $result['staff']) . PHP_EOL);
        fwrite(STDOUT, "public_request_url={$result['public_url']}\n");
        fwrite(STDOUT, 'note=Demo data only. Rotate these credentials before a real pilot.' . PHP_EOL);

        return 0;
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
