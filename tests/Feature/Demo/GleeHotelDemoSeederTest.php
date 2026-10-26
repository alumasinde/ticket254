<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Services\Demo\GleeHotelDemoSeeder;
use RuntimeException;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class GleeHotelDemoSeederTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_it_builds_a_complete_glee_hotel_workspace(): void
    {
        /** @var GleeHotelDemoSeeder $seeder */
        $seeder = $this->app->container()->get(GleeHotelDemoSeeder::class);

        $slug = $this->uniqueSlug('glee-demo');
        $email = $this->uniqueEmail('demo-owner');

        $result = $seeder->seed([
            'email' => $email,
            'password' => 'a-long-demo-passphrase',
            'slug' => $slug,
        ]);

        $this->assertSame($slug, $result['slug']);
        $this->assertSame($email, $result['owner_email']);
        $this->assertFalse($result['generated_password']);
        $this->assertSame(6, $result['members']);
        $this->assertSame(3, $result['sites']);
        $this->assertSame(14, $result['tickets']);
        $this->assertCount(5, $result['staff']);
        $this->assertStringContainsString('/request/' . $slug, $result['public_url']);

        $tenantId = (int) $result['tenant_id'];

        $owner = $this->db()->table('users')->where('email', $email)->first();
        $this->assertNotNull($owner);

        $memberships = $this->db()->select(
            'SELECT m.role_id, r.code, m.status FROM tenant_memberships m '
            . 'INNER JOIN roles r ON r.id = m.role_id '
            . 'WHERE m.tenant_id = ? ORDER BY r.code',
            [$tenantId],
        );

        $this->assertCount(6, $memberships);
        $roles = array_column($memberships, 'code');
        $this->assertSame(1, count(array_keys($roles, 'owner', true)));
        $this->assertSame(1, count(array_keys($roles, 'admin', true)));
        $this->assertSame(4, count(array_keys($roles, 'agent', true)));

        $ticketCount = $this->db()->table('tickets')->where('tenant_id', $tenantId)->count();
        $this->assertSame(14, $ticketCount);

        $statuses = $this->db()->select(
            'SELECT st.code, COUNT(*) AS total FROM tickets t '
            . 'INNER JOIN ticket_statuses st ON st.id = t.status_id '
            . 'WHERE t.tenant_id = ? GROUP BY st.code',
            [$tenantId],
        );
        $byCode = array_column($statuses, 'total', 'code');
        $this->assertGreaterThan(0, $byCode['in_progress'] ?? 0);
        $this->assertGreaterThan(0, $byCode['pending'] ?? 0);
        $this->assertGreaterThan(0, $byCode['resolved'] ?? 0);
        $this->assertGreaterThan(0, $byCode['closed'] ?? 0);
        $this->assertGreaterThan(0, $byCode['open'] ?? 0);

        $unassigned = $this->db()->table('tickets')
            ->where('tenant_id', $tenantId)
            ->whereNull('assigned_user_id')
            ->count();
        $this->assertGreaterThan(0, $unassigned);

        $overdue = $this->db()->selectOne(
            'SELECT COUNT(*) AS total FROM tickets '
            . 'WHERE tenant_id = ? AND due_at IS NOT NULL AND due_at < UTC_TIMESTAMP()',
            [$tenantId],
        );
        $this->assertSame(3, (int) $overdue['total']);

        $sites = $this->db()->table('sites')->where('tenant_id', $tenantId)->count();
        $this->assertSame(3, $sites);

        $events = $this->db()->table('ticket_events')->where('tenant_id', $tenantId)->count();
        $this->assertGreaterThanOrEqual(14, $events);

        $audit = $this->db()->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('action', 'demo.seeded')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('tenant', $audit['resource_type']);
    }

    public function test_it_refuses_to_seed_the_same_slug_twice(): void
    {
        /** @var GleeHotelDemoSeeder $seeder */
        $seeder = $this->app->container()->get(GleeHotelDemoSeeder::class);

        $slug = $this->uniqueSlug('glee-demo');
        $seeder->seed([
            'email' => $this->uniqueEmail('demo-owner'),
            'password' => 'a-long-demo-passphrase',
            'slug' => $slug,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $seeder->seed([
            'email' => $this->uniqueEmail('demo-owner'),
            'password' => 'a-long-demo-passphrase',
            'slug' => $slug,
        ]);
    }

    public function test_it_generates_a_password_when_none_is_given(): void
    {
        /** @var GleeHotelDemoSeeder $seeder */
        $seeder = $this->app->container()->get(GleeHotelDemoSeeder::class);

        $result = $seeder->seed([
            'email' => $this->uniqueEmail('demo-owner'),
            'slug' => $this->uniqueSlug('glee-demo'),
        ]);

        $this->assertTrue($result['generated_password']);
        $this->assertGreaterThanOrEqual(12, mb_strlen((string) $result['password']));
    }
}
