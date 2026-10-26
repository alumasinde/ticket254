<?php

declare(strict_types=1);

/**
 * Test helper: allocate ticket numbers from a separate PHP process so the
 * concurrency test can prove the counter is atomic across real connections.
 *
 * Usage: php allocate_ticket_number.php <tenant_id> <count>
 * Prints a comma-separated list of allocated numbers.
 */

define('BASE_PATH', dirname(__DIR__, 2));

foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost',
    'DB_NAME' => 'hudumadesk_test',
] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require BASE_PATH . '/vendor/autoload.php';

$tenantId = (int) ($argv[1] ?? 0);
$count = max(1, (int) ($argv[2] ?? 1));

if ($tenantId < 1) {
    fwrite(STDERR, 'A tenant id is required.' . PHP_EOL);
    exit(2);
}

$app = new App\Core\Application(BASE_PATH);
$app->boot();

$counter = new App\Repositories\TicketCounterRepository(
    $app->container()->get(App\Database\Database::class),
);

$numbers = [];

for ($i = 0; $i < $count; $i++) {
    $numbers[] = $counter->allocate($tenantId);
}

echo implode(',', $numbers);
