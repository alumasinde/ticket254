<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\LoginUser;
use App\Services\Auth\RegisterWorkspace;
use App\Services\Auth\SessionAuth;
use App\Services\Tenants\TenantContext;

final class AuthController
{
    public function __construct(
        private readonly RegisterWorkspace $registerWorkspace,
        private readonly LoginUser $loginUser,
        private readonly SessionAuth $auth,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
    ) {
    }

    public function register(RegisterRequest $request): Response
    {
        $result = $this->registerWorkspace->execute($request->validated());

        $this->auth->start((int) $result['user']['id'], (int) $result['tenant']['id'], 1);

        return Response::json([
            'data' => [
                'user' => $result['user'],
                'workspace' => [
                    'id' => $result['tenant']['id'],
                    'name' => $result['tenant']['name'],
                    'slug' => $result['tenant']['slug'],
                    'public_form_url' => rtrim((string) config('app.url'), '/')
                        . '/request/' . $result['tenant']['slug'],
                    'timezone' => $result['tenant']['timezone'],
                ],
                'role' => $result['role'],
            ],
        ], 201);
    }

    public function login(LoginRequest $request): Response
    {
        $result = $this->loginUser->attempt(
            (string) $request->input('email'),
            (string) $request->input('password'),
        );

        if ($result === null) {
            throw new HttpException(
                401,
                'The email or password is incorrect.',
                [],
                'invalid_credentials',
            );
        }

        $user = $result['user'];

        return Response::json([
            'data' => [
                'user' => [
                    'id' => (int) $user['id'],
                    'first_name' => (string) $user['first_name'],
                    'last_name' => (string) $user['last_name'],
                ],
                'workspaces' => $result['workspaces'],
                'active_workspace_id' => $result['active_workspace_id'],
            ],
        ]);
    }

    public function logout(): Response
    {
        $this->auth->destroy();

        return Response::noContent();
    }

    public function me(TenantContext $context): Response
    {
        $user = $this->users->findById($context->userId);
        $tenant = $this->tenants->findById($context->tenantId);

        return Response::json([
            'data' => [
                'user' => [
                    'id' => (int) $user['id'],
                    'first_name' => (string) $user['first_name'],
                    'last_name' => (string) $user['last_name'],
                    'email' => (string) $user['email'],
                ],
                'workspace' => [
                    'id' => (int) $tenant['id'],
                    'name' => (string) $tenant['name'],
                    'timezone' => (string) $tenant['timezone'],
                ],
                'role' => $context->role,
                'permissions' => $context->permissions(),
                'csrf_token' => csrf_token(),
            ],
        ]);
    }
}
