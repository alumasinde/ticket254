<?php

declare(strict_types=1);

use App\Core\Application;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    $message = 'Dependencies are not installed. Run: composer install';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit(1);
}

require BASE_PATH . '/vendor/autoload.php';

$app = new Application(BASE_PATH);
$app->boot();

return $app;
