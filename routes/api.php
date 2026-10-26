<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SettingsController;
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

    $router->post('/tickets/{ref}/assign', [TicketController::class, 'assign'])
        ->name('api.tickets.assign')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/status', [TicketController::class, 'transition'])
        ->name('api.tickets.status')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/priority', [TicketController::class, 'priority'])
        ->name('api.tickets.priority')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/messages', [TicketController::class, 'message'])
        ->name('api.tickets.messages')
        ->middleware('csrf');

    $router->get('/tickets/{ref}/attachments/{attachmentId}', [TicketController::class, 'download'])
        ->name('api.tickets.attachments.download')
        ->can('tickets.view_team');

    $router->post('/tickets/{ref}/tracking-token/regenerate', [TicketController::class, 'regenerateTracking'])
        ->name('api.tickets.tracking.regenerate')
        ->middleware('csrf')
        ->can('tickets.status');

    $router->get('/settings', [SettingsController::class, 'show'])
        ->name('api.settings.show')
        ->can('settings.manage');

    $router->patch('/settings', [SettingsController::class, 'update'])
        ->name('api.settings.update')
        ->middleware('csrf')
        ->can('settings.manage');

    $router->patch('/settings/public-form', [SettingsController::class, 'updatePublicForm'])
        ->name('api.settings.public-form')
        ->middleware('csrf')
        ->can('settings.manage');

    $router->post('/settings/public-form/regenerate', [SettingsController::class, 'regenerate'])
        ->name('api.settings.public-form.regenerate')
        ->middleware('csrf')
        ->can('settings.manage');

    $router->patch('/settings/slug', [SettingsController::class, 'updateSlug'])
        ->name('api.settings.slug')
        ->middleware('csrf')
        ->can('settings.manage');
});
