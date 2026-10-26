<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Console\Commands\PlatformGrantCommand;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class PlatformGrantCommandTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_granting_platform_access_opens_the_platform_console(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->assertSame(403, $this->get('/platform', ['Accept' => 'application/json'])->status());

        /** @var PlatformGrantCommand $command */
        $command = $this->app->container()->get(PlatformGrantCommand::class);
        $this->assertSame(0, $command->handle([$fixture['email']]));

        $user = $this->db()->table('users')->where('id', $fixture['user_id'])->first();
        $this->assertSame(1, (int) $user['is_platform_admin']);

        $this->assertSame(200, $this->loginAs($fixture)->status());
        $this->assertSame(200, $this->get('/platform')->status());
    }

    public function test_it_rejects_an_unknown_email(): void
    {
        /** @var PlatformGrantCommand $command */
        $command = $this->app->container()->get(PlatformGrantCommand::class);

        $this->assertSame(1, $command->handle([$this->uniqueEmail('missing')]));
    }
}
