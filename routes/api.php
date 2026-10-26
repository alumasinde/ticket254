<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TicketController;

/** @var Router $router */
$router->group(['prefix' => '/api/v1'], function (Router $router): void {
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

$router->group(['prefix' => '/api/v1', 'middleware' => ['auth', 'tenant']], function (Router $router): void {
    $router->get('/tickets', [TicketController::class, 'index'])
        ->name('api.tickets.index')
        ->can('tickets.view_team');

    $router->post('/tickets', [TicketController::class, 'store'])
        ->name('api.tickets.store')
        ->middleware('csrf')
        ->can('tickets.create');

    $router->get('/tickets/{ref}', [TicketController::class, 'show'])
        ->name('api.tickets.show')
        ->can('tickets.view_team');

    $router->patch('/tickets/{ref}', [TicketController::class, 'update'])
        ->name('api.tickets.update')
        ->middleware('csrf');
});
