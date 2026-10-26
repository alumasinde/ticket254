<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\DatabaseBackup;
use Tests\TestCase;

final class DatabaseBackupTest extends TestCase
{
    public function test_a_dump_restores_into_a_scratch_schema_with_matching_counts(): void
    {
        /** @var DatabaseBackup $backup */
        $backup = $this->app->container()->get(DatabaseBackup::class);

        if (!$backup->binaryAvailable()) {
            $this->markTestSkipped('The mysql and mysqldump clients are not available.');
        }

        $report = $backup->verify();

        try {
            $this->assertFileExists($report['path']);
            $this->assertGreaterThan(0, $report['bytes']);
            $this->assertTrue($report['matches'], 'Restored row counts must match the source.');
            $this->assertArrayHasKey('tickets', $report['tables']);
            $this->assertArrayHasKey('users', $report['tables']);

            foreach ($report['tables'] as $table => $counts) {
                $this->assertSame($counts['source'], $counts['restored'], "Row count differs for [$table].");
            }
        } finally {
            @unlink($report['path']);
        }
    }
}
