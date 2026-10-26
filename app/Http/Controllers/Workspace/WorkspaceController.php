<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Response;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Tenants\TenantContext;

abstract class WorkspaceController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function page(TenantContext $ctx, string $template, array $data, string $nav): Response
    {
        return view($template, array_merge([
            'user' => $this->users->findById($ctx->userId),
            'workspace' => $this->tenants->findById($ctx->tenantId),
            'role' => $ctx->role,
            'nav' => $nav,
            'errors' => session('errors') ?? [],
        ], $data));
    }
}
