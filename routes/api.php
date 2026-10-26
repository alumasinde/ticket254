<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
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

    $router->post('/invitations/accept', [InvitationController::class, 'accept'])
        ->name('api.invitations.accept');
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

    $router->post('/tickets/{ref}/attachments', [TicketController::class, 'uploadAttachment'])
        ->name('api.tickets.attachments.store')
        ->middleware('csrf')
        ->can('tickets.attach');

    $router->delete('/tickets/{ref}/attachments/{attachmentId}', [TicketController::class, 'deleteAttachment'])
        ->name('api.tickets.attachments.delete')
        ->middleware('csrf')
        ->can('tickets.attach');

    $router->post('/tickets/{ref}/tracking-token/regenerate', [TicketController::class, 'regenerateTracking'])
        ->name('api.tickets.tracking.regenerate')
        ->middleware('csrf')
        ->can('tickets.status');

    $router->get('/dashboard', [DashboardController::class, 'show'])
        ->name('api.dashboard.show')
        ->can('reports.view_team');

    $router->get('/audit-logs', [AuditController::class, 'index'])
        ->name('api.audit.index')
        ->can('audit.view');

    $router->get('/teams', [DirectoryController::class, 'teams'])
        ->name('api.teams.index')
        ->can('teams.manage');

    $router->post('/teams', [DirectoryController::class, 'storeTeam'])
        ->name('api.teams.store')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->patch('/teams/{id}', [DirectoryController::class, 'updateTeam'])
        ->name('api.teams.update')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->delete('/teams/{id}', [DirectoryController::class, 'deleteTeam'])
        ->name('api.teams.delete')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->post('/teams/{id}/members', [DirectoryController::class, 'addTeamMember'])
        ->name('api.teams.members.store')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->delete('/teams/{id}/members/{userId}', [DirectoryController::class, 'removeTeamMember'])
        ->name('api.teams.members.delete')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->get('/sites', [DirectoryController::class, 'sites'])
        ->name('api.sites.index')
        ->can('sites.manage');

    $router->post('/sites', [DirectoryController::class, 'storeSite'])
        ->name('api.sites.store')
        ->middleware('csrf')
        ->can('sites.manage');

    $router->patch('/sites/{id}', [DirectoryController::class, 'updateSite'])
        ->name('api.sites.update')
        ->middleware('csrf')
        ->can('sites.manage');

    $router->delete('/sites/{id}', [DirectoryController::class, 'deleteSite'])
        ->name('api.sites.delete')
        ->middleware('csrf')
        ->can('sites.manage');

    $router->get('/categories', [DirectoryController::class, 'categories'])
        ->name('api.categories.index')
        ->can('categories.manage');

    $router->post('/categories', [DirectoryController::class, 'storeCategory'])
        ->name('api.categories.store')
        ->middleware('csrf')
        ->can('categories.manage');

    $router->patch('/categories/{id}', [DirectoryController::class, 'updateCategory'])
        ->name('api.categories.update')
        ->middleware('csrf')
        ->can('categories.manage');

    $router->delete('/categories/{id}', [DirectoryController::class, 'deleteCategory'])
        ->name('api.categories.delete')
        ->middleware('csrf')
        ->can('categories.manage');

    $router->get('/members', [MemberController::class, 'index'])
        ->name('api.members.index')
        ->can('members.manage');

    $router->patch('/members/{userId}', [MemberController::class, 'update'])
        ->name('api.members.update')
        ->middleware('csrf')
        ->can('members.manage');

    $router->delete('/members/{userId}', [MemberController::class, 'destroy'])
        ->name('api.members.destroy')
        ->middleware('csrf')
        ->can('members.manage');

    $router->post('/invitations', [MemberController::class, 'invite'])
        ->name('api.invitations.store')
        ->middleware('csrf')
        ->can('members.manage');

    $router->post('/invitations/{id}/resend', [MemberController::class, 'resend'])
        ->name('api.invitations.resend')
        ->middleware('csrf')
        ->can('members.manage');

    $router->delete('/invitations/{id}', [MemberController::class, 'revoke'])
        ->name('api.invitations.revoke')
        ->middleware('csrf')
        ->can('members.manage');

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
