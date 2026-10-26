<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Response;
use App\Repositories\CategoryRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Tenants\TenantContext;

final class HomeController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly TeamRepository $teams,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function index(TenantContext $context): Response
    {
        $user = $this->users->findById($context->userId);
        $tenant = $this->tenants->findById($context->tenantId);

        return view('app/home.twig', [
            'user' => $user,
            'workspace' => $tenant,
            'role' => $context->role,
            'teams' => $this->teams->all($context),
            'categories' => $this->categories->all($context),
        ]);
    }
}
