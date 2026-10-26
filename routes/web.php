<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Workspace\HomeController as WorkspaceHomeController;

/** @var App\Core\Router $router */
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

$router->get('/app', [WorkspaceHomeController::class, 'index'])
    ->name('app.home')
    ->middleware(['auth', 'tenant']);
