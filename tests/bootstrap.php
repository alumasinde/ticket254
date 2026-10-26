<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Database;
use App\Database\Migrator;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

if (is_file(BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
}

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost',
    'DB_NAME' => 'hudumadesk_test',
    'SESSION_SECURE' => 'false',
    'LOG_LEVEL' => 'debug',
];

foreach ($testEnvironment as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

$config = new Config();
$config->loadDirectory(BASE_PATH . '/config');

$connection = $config->get('database.connections.mysql');
$database = (string) $connection['database'];

if (preg_match('/^[A-Za-z0-9_]+_test$/', $database) !== 1) {
    throw new RuntimeException("Refusing to reset database [$database]: test databases must end in _test.");
}

$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $connection['host'], $connection['port'], $connection['charset']);
$pdo = new PDO($dsn, $connection['username'], $connection['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$migrator = new Migrator(new Database($connection), BASE_PATH . '/db');
$migrator->migrate();
