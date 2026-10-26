<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;

/** @var App\Core\Router $router */
$router->group(['prefix' => '/api/v1'], function (App\Core\Router $router): void {
    // Registration and login run before a session exists, so they are not CSRF-checked.
    $router->post('/auth/register', [AuthController::class, 'register'])
        ->name('api.auth.register');

    $router->post('/auth/login', [AuthController::class, 'login'])
        ->name('api.auth.login')
        ->middleware('throttle.login');

    $router->post('/auth/logout', [AuthController::class, 'logout'])
        ->name('api.auth.logout')
        ->middleware(['auth', 'csrf']);

    $router->get('/auth/me', [AuthController::class, 'me'])
        ->name('api.auth.me')
        ->middleware(['auth', 'tenant']);
});
