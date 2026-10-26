<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Database\Database;
use App\Repositories\UserRepository;

final class PlatformGrantCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Database $db,
    ) {
    }

    public function name(): string
    {
        return 'platform:grant';
    }

    public function description(): string
    {
        return 'Grant platform-admin access: platform:grant <email>.';
    }

    public function handle(array $arguments): int
    {
        $email = $this->email($arguments);

        if ($email === null) {
            fwrite(STDERR, 'Usage: php hd platform:grant <email>' . PHP_EOL);

            return 1;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            fwrite(STDERR, sprintf('No user found for [%s].%s', $email, PHP_EOL));

            return 1;
        }

        if ((int) $user['is_platform_admin'] === 1) {
            fwrite(STDOUT, sprintf('%s is already a platform admin.%s', (string) $user['email'], PHP_EOL));

            return 0;
        }

        $this->db->table('users')->where('id', (int) $user['id'])->update(['is_platform_admin' => 1]);

        fwrite(STDOUT, sprintf(
            "granted=%s platform_admin=yes sign_in=/login console=/platform%s",
            (string) $user['email'],
            PHP_EOL,
        ));

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function email(array $arguments): ?string
    {
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--email=')) {
                return trim(substr($argument, 8));
            }

            if ($argument === '--email' && isset($arguments[$index + 1])) {
                return trim($arguments[$index + 1]);
            }

            if (!str_starts_with($argument, '--')) {
                return trim($argument);
            }
        }

        return null;
    }
}
