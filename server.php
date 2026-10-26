<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicDirectory = realpath(__DIR__ . '/public');
$requestedFile = realpath($publicDirectory . $path);

if (
    $path !== '/'
    && $requestedFile !== false
    && str_starts_with($requestedFile, $publicDirectory . DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    return false;
}

require $publicDirectory . '/index.php';
