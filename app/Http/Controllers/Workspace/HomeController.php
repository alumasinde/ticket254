<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Dashboard\DashboardService;
use App\Services\Tenants\TenantContext;

final class HomeController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly DashboardService $dashboard,
    ) {
        parent::__construct($users, $tenants);
    }

    public function index(Request $request, TenantContext $context): Response
    {
        $query = $request->query();

        return $this->page($context, 'app/home.twig', [
            'dashboard' => $this->dashboard->data($context, $query),
            'period' => (string) ($query['period'] ?? '7d'),
        ], 'workspace');
    }
}
