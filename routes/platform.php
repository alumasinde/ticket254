<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Api\PlatformController as ApiPlatformController;
use App\Http\Controllers\Platform\PlatformController;

/** @var Router $router */
$router->group(['prefix' => '/platform', 'middleware' => ['auth', 'platform']], function (Router $router): void {
    $router->get('', [PlatformController::class, 'index'])->name('platform.index');
    $router->get('/tenants', [PlatformController::class, 'tenants'])->name('platform.tenants');
    $router->get('/tenants/{id}', [PlatformController::class, 'show'])->name('platform.tenants.show');
    $router->get('/health', [PlatformController::class, 'health'])->name('platform.health');

    $router->post('/tenants/{id}/suspend', [PlatformController::class, 'suspend'])
        ->name('platform.tenants.suspend')
        ->middleware('csrf');

    $router->post('/tenants/{id}/reactivate', [PlatformController::class, 'reactivate'])
        ->name('platform.tenants.reactivate')
        ->middleware('csrf');

    $router->patch('/tenants/{id}/subscription', [PlatformController::class, 'subscription'])
        ->name('platform.tenants.subscription')
        ->middleware('csrf');
});

$router->group(['prefix' => '/api/v1/platform', 'middleware' => ['auth', 'platform']], function (Router $router): void {
    $router->get('/overview', [ApiPlatformController::class, 'overview'])
        ->name('api.platform.overview');

    $router->get('/tenants', [ApiPlatformController::class, 'tenants'])
        ->name('api.platform.tenants');

    $router->get('/tenants/{id}', [ApiPlatformController::class, 'show'])
        ->name('api.platform.tenants.show');

    $router->post('/tenants/{id}/suspend', [ApiPlatformController::class, 'suspend'])
        ->name('api.platform.tenants.suspend')
        ->middleware('csrf');

    $router->post('/tenants/{id}/reactivate', [ApiPlatformController::class, 'reactivate'])
        ->name('api.platform.tenants.reactivate')
        ->middleware('csrf');

    $router->patch('/tenants/{id}/subscription', [ApiPlatformController::class, 'subscription'])
        ->name('api.platform.tenants.subscription')
        ->middleware('csrf');

    $router->get('/health', [ApiPlatformController::class, 'health'])
        ->name('api.platform.health');
});
