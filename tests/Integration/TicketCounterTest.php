<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\TenantRepository;
use App\Repositories\TicketCounterRepository;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketCounterTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_allocations_start_at_one_and_increment_within_a_workspace(): void
    {
        $fixture = $this->registerWorkspaceFixture('Counter Lodge');
        $counter = $this->app->container()->get(TicketCounterRepository::class);

        $this->assertSame(1, $counter->allocate($fixture['tenant_id']));
        $this->assertSame(2, $counter->allocate($fixture['tenant_id']));
        $this->assertSame(3, $counter->allocate($fixture['tenant_id']));

        $row = $this->db()->table('ticket_counters')->where('tenant_id', $fixture['tenant_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['last_number']);
    }

    public function test_a_workspace_without_a_counter_row_is_created_on_first_allocation(): void
    {
        $tenantId = $this->app->container()->get(TenantRepository::class)->create([
            'name' => 'No Counter Lodge',
            'slug' => $this->uniqueSlug('no-counter'),
            'public_key' => bin2hex(random_bytes(16)),
            'status' => 'active',
            'timezone' => 'Africa/Nairobi',
        ]);

        $this->assertNull($this->db()->table('ticket_counters')->where('tenant_id', $tenantId)->first());

        $counter = $this->app->container()->get(TicketCounterRepository::class);

        $this->assertSame(1, $counter->allocate($tenantId));
        $this->assertSame(2, $counter->allocate($tenantId));
    }

    public function test_each_workspace_keeps_its_own_sequence(): void
    {
        $first = $this->registerWorkspaceFixture('First Counter Lodge');
        $second = $this->registerWorkspaceFixture('Second Counter Lodge');
        $counter = $this->app->container()->get(TicketCounterRepository::class);

        $this->assertSame(1, $counter->allocate($first['tenant_id']));
        $this->assertSame(1, $counter->allocate($second['tenant_id']));
        $this->assertSame(2, $counter->allocate($first['tenant_id']));
        $this->assertSame(2, $counter->allocate($second['tenant_id']));
    }

    public function test_parallel_processes_receive_unique_sequential_numbers(): void
    {
        $fixture = $this->registerWorkspaceFixture('Parallel Lodge');
        $script = BASE_PATH . '/tests/Support/allocate_ticket_number.php';

        $this->assertFileExists($script);

        $workers = 6;
        $perWorker = 20;
        $processes = [];

        for ($i = 0; $i < $workers; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $script, (string) $fixture['tenant_id'], (string) $perWorker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                BASE_PATH,
            );

            $this->assertIsResource($process, 'Could not start an allocation worker.');
            $processes[] = ['process' => $process, 'pipes' => $pipes];
        }

        $numbers = [];
        $failures = [];

        foreach ($processes as $worker) {
            $pipes = $worker['pipes'];
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_close($worker['process']);

            if ($status !== 0 || trim($stderr) !== '') {
                $failures[] = "exit {$status}: " . trim($stderr);
                continue;
            }

            foreach (explode(',', trim($stdout)) as $number) {
                $numbers[] = (int) $number;
            }
        }

        $this->assertSame([], $failures, implode(PHP_EOL, $failures));
        $this->assertCount($workers * $perWorker, $numbers);

        sort($numbers);

        $this->assertSame(range(1, $workers * $perWorker), $numbers);

        $row = $this->db()->table('ticket_counters')->where('tenant_id', $fixture['tenant_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($workers * $perWorker, (int) $row['last_number']);
    }
}
