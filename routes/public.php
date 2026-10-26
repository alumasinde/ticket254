<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\PublicSite\InvitationController;
use App\Http\Controllers\PublicSite\RequestController;
use App\Http\Controllers\PublicSite\TrackingController;

/** @var Router $router */

// Public API. No account and no CSRF token: abuse is handled by the honeypot and rate limits.
$router->get('/api/v1/public/workspaces/{identifier}', [PublicController::class, 'configuration'])
    ->name('api.public.workspace');

$router->post('/api/v1/public/workspaces/{identifier}/tickets', [PublicController::class, 'store'])
    ->name('api.public.tickets.store');

$router->get('/api/v1/track/{token}', [PublicController::class, 'track'])
    ->name('api.public.track');

$router->post('/api/v1/track/{token}/replies', [PublicController::class, 'reply'])
    ->name('api.public.replies');

$router->get('/api/v1/slugs/check', [PublicController::class, 'checkSlug'])
    ->name('api.slugs.check');

// Public pages.
$router->get('/request', [RequestController::class, 'show'])
    ->name('public.request.show');

$router->post('/request', [RequestController::class, 'store'])
    ->name('public.request.store')
    ->middleware('csrf');

$router->get('/request/{identifier}', [RequestController::class, 'show'])
    ->name('public.request.show.identifier');

$router->post('/request/{identifier}', [RequestController::class, 'store'])
    ->name('public.request.store.identifier')
    ->middleware('csrf');

$router->get('/track/{token}', [TrackingController::class, 'show'])
    ->name('public.track.show');

$router->post('/track/{token}', [TrackingController::class, 'reply'])
    ->name('public.track.reply')
    ->middleware('csrf');

$router->get('/invite/{token}', [InvitationController::class, 'show'])
    ->name('public.invite.show');

$router->post('/invite/{token}', [InvitationController::class, 'accept'])
    ->name('public.invite.accept')
    ->middleware('csrf');
