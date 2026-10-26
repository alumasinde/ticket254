<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;

/** @var App\Core\Router $router */
$router->get('/', [HomeController::class, 'index'])->name('home');
