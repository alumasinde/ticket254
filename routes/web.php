<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Workspace\HomeController as WorkspaceHomeController;
use App\Http\Controllers\Workspace\SettingsController;
use App\Http\Controllers\Workspace\TicketController;

/** @var Router $router */
$router->get('/', [HomeController::class, 'index'])->name('home');

$router->get('/register', [AuthController::class, 'showRegister'])
    ->name('register')
    ->middleware('guest');

$router->post('/register', [AuthController::class, 'register'])
    ->name('register.store')
    ->middleware(['guest', 'csrf']);

$router->get('/login', [AuthController::class, 'showLogin'])
    ->name('login')
    ->middleware('guest');

$router->post('/login', [AuthController::class, 'login'])
    ->name('login.store')
    ->middleware(['guest', 'csrf', 'throttle.login']);

$router->post('/logout', [AuthController::class, 'logout'])
    ->name('logout')
    ->middleware(['auth', 'csrf']);

$router->group(['prefix' => '/app', 'middleware' => ['auth', 'tenant']], function (Router $router): void {
    $router->get('', [WorkspaceHomeController::class, 'index'])->name('app.home');

    $router->get('/tickets', [TicketController::class, 'index'])
        ->name('tickets.index')
        ->can('tickets.view_team');

    $router->get('/tickets/create', [TicketController::class, 'create'])
        ->name('tickets.create')
        ->can('tickets.create');

    $router->post('/tickets', [TicketController::class, 'store'])
        ->name('tickets.store')
        ->middleware('csrf')
        ->can('tickets.create');

    $router->get('/tickets/{ref}', [TicketController::class, 'show'])
        ->name('tickets.show')
        ->can('tickets.view_team');

    $router->patch('/tickets/{ref}', [TicketController::class, 'update'])
        ->name('tickets.update')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/assign', [TicketController::class, 'assign'])
        ->name('tickets.assign')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/status', [TicketController::class, 'transition'])
        ->name('tickets.status')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/priority', [TicketController::class, 'priority'])
        ->name('tickets.priority')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/messages', [TicketController::class, 'message'])
        ->name('tickets.messages.store')
        ->middleware('csrf');

    $router->post('/tickets/{ref}/tracking-token/regenerate', [TicketController::class, 'regenerateTracking'])
        ->name('tickets.tracking.regenerate')
        ->middleware('csrf')
        ->can('tickets.status');

    $router->get('/settings', [SettingsController::class, 'show'])
        ->name('settings.show')
        ->can('settings.manage');

    $router->patch('/settings', [SettingsController::class, 'updateProfile'])
        ->name('settings.update')
        ->middleware('csrf')
        ->can('settings.manage');

    $router->patch('/settings/public-form', [SettingsController::class, 'updatePublicForm'])
        ->name('settings.public-form')
        ->middleware('csrf')
        ->can('settings.manage');

    $router->patch('/settings/slug', [SettingsController::class, 'updateSlug'])
        ->name('settings.slug')
        ->middleware('csrf')
        ->can('settings.manage');

    $router->post('/settings/regenerate', [SettingsController::class, 'regenerate'])
        ->name('settings.regenerate')
        ->middleware('csrf')
        ->can('settings.manage');
});
