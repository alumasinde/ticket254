<?php

declare(strict_types=1);

return [
    'directory' => (string) env('BACKUP_DIR', BASE_PATH . '/storage/backups'),
    'retain_days' => max(1, (int) env('BACKUP_RETAIN_DAYS', 14)),

    // Empty means detect the XAMPP binary on Windows, then fall back to PATH.
    'mysqldump_bin' => (string) env('MYSQLDUMP_BIN', ''),
    'mysql_bin' => (string) env('MYSQL_BIN', ''),
];
