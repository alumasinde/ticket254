<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Workspace\AuditController;
use App\Http\Controllers\Workspace\DirectoryController;
use App\Http\Controllers\Workspace\HomeController as WorkspaceHomeController;
use App\Http\Controllers\Workspace\MemberController;
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

    $router->post('/tickets/{ref}/attachments', [TicketController::class, 'uploadAttachment'])
        ->name('tickets.attachments.store')
        ->middleware('csrf')
        ->can('tickets.attach');

    $router->delete('/tickets/{ref}/attachments/{attachmentId}', [TicketController::class, 'deleteAttachment'])
        ->name('tickets.attachments.delete')
        ->middleware('csrf')
        ->can('tickets.attach');

    $router->post('/tickets/{ref}/tracking-token/regenerate', [TicketController::class, 'regenerateTracking'])
        ->name('tickets.tracking.regenerate')
        ->middleware('csrf')
        ->can('tickets.status');

    $router->get('/teams', [DirectoryController::class, 'teams'])
        ->name('teams.index')
        ->can('teams.manage');

    $router->post('/teams', [DirectoryController::class, 'storeTeam'])
        ->name('teams.store')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->patch('/teams/{id}', [DirectoryController::class, 'updateTeam'])
        ->name('teams.update')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->delete('/teams/{id}', [DirectoryController::class, 'archiveTeam'])
        ->name('teams.archive')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->post('/teams/{id}/members', [DirectoryController::class, 'addTeamMember'])
        ->name('teams.members.store')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->delete('/teams/{id}/members/{userId}', [DirectoryController::class, 'removeTeamMember'])
        ->name('teams.members.delete')
        ->middleware('csrf')
        ->can('teams.manage');

    $router->get('/categories', [DirectoryController::class, 'categories'])
        ->name('categories.index')
        ->can('categories.manage');

    $router->post('/sites', [DirectoryController::class, 'storeSite'])
        ->name('sites.store')
        ->middleware('csrf')
        ->can('sites.manage');

    $router->patch('/sites/{id}', [DirectoryController::class, 'updateSite'])
        ->name('sites.update')
        ->middleware('csrf')
        ->can('sites.manage');

    $router->delete('/sites/{id}', [DirectoryController::class, 'archiveSite'])
        ->name('sites.archive')
        ->middleware('csrf')
        ->can('sites.manage');

    $router->post('/categories', [DirectoryController::class, 'storeCategory'])
        ->name('categories.store')
        ->middleware('csrf')
        ->can('categories.manage');

    $router->patch('/categories/{id}', [DirectoryController::class, 'updateCategory'])
        ->name('categories.update')
        ->middleware('csrf')
        ->can('categories.manage');

    $router->delete('/categories/{id}', [DirectoryController::class, 'archiveCategory'])
        ->name('categories.archive')
        ->middleware('csrf')
        ->can('categories.manage');

    $router->get('/members', [MemberController::class, 'index'])
        ->name('members.index')
        ->can('members.manage');

    $router->patch('/members/{userId}', [MemberController::class, 'update'])
        ->name('members.update')
        ->middleware('csrf')
        ->can('members.manage');

    $router->delete('/members/{userId}', [MemberController::class, 'remove'])
        ->name('members.remove')
        ->middleware('csrf')
        ->can('members.manage');

    $router->post('/invitations', [MemberController::class, 'invite'])
        ->name('invitations.store')
        ->middleware('csrf')
        ->can('members.manage');

    $router->post('/invitations/{id}/resend', [MemberController::class, 'resend'])
        ->name('invitations.resend')
        ->middleware('csrf')
        ->can('members.manage');

    $router->delete('/invitations/{id}', [MemberController::class, 'revoke'])
        ->name('invitations.revoke')
        ->middleware('csrf')
        ->can('members.manage');

    $router->get('/audit', [AuditController::class, 'index'])
        ->name('audit.index')
        ->can('audit.view');

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
