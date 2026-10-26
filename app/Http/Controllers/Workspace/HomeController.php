<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Response;
use App\Repositories\CategoryRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Tenants\TenantContext;

final class HomeController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly TeamRepository $teams,
        private readonly CategoryRepository $categories,
    ) {
        parent::__construct($users, $tenants);
    }

    public function index(TenantContext $context): Response
    {
        return $this->page($context, 'app/home.twig', [
            'teams' => $this->teams->all($context),
            'categories' => $this->categories->all($context),
        ], 'workspace');
    }
}
